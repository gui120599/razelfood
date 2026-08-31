<?php

namespace App\Actions\Orders;

use App\Actions\Menu\CheckBusinessHours;
use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Actions\Orders\Support\RecordsOrderPayments;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Exceptions\CheckoutException;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\CurrentTenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Núcleo transacional do checkout. Nunca confia em preço/saldo vindo do
 * carrinho — recalcula tudo no servidor (RN-13). O lock/validação/consumo
 * de estoque e promoção relâmpago vive em CartStockAndPromotionLedger,
 * compartilhado com UpdateOrderFromCart (edição de pedido pelo painel); a
 * validação/gravação das parcelas de pagamento vive em RecordsOrderPayments,
 * pelo mesmo motivo.
 */
class CreateOrderFromCart
{
    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly RecordsOrderPayments $payments,
    ) {}

    /**
     * @param  array<int, array{type: string, product_id: int, flavor_ids: array<int>, quantity: int, note: ?string, addons?: array<int, array{addon_id:int, quantity:int, target:?int}>}>  $cartItems
     * @param  array{phone: string, name: string, cpf?: ?string, zip_code: ?string, street: ?string, number: ?string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string, delivery_option_id: ?int, payments: array<int, array{payment_option_id: int, amount: float, change_for: ?float}>, notes: ?string}  $checkoutData
     * @param  OrderOrigin  $origin  Origem gravada no pedido — Menu (cardápio público, default) ou Staff (lançado pelo painel do tenant).
     * @param  bool  $bypassBusinessHours  Pula a checagem de horário de funcionamento — só usado pelo painel (atendente pode lançar mesmo "fechado").
     */
    public function __invoke(
        array $cartItems,
        array $checkoutData,
        OrderOrigin $origin = OrderOrigin::Menu,
        bool $bypassBusinessHours = false,
    ): Order {
        if (empty($cartItems)) {
            throw new CheckoutException('Seu carrinho está vazio.');
        }

        if (empty($checkoutData['payments'])) {
            throw new CheckoutException('Escolha ao menos uma forma de pagamento.');
        }

        if (! $bypassBusinessHours) {
            $businessHours = app(CheckBusinessHours::class)();

            if (! $businessHours->isOpen) {
                throw new CheckoutException($businessHours->message ?? 'Estamos fechados no momento.');
            }
        }

        return DB::transaction(function () use ($cartItems, $checkoutData, $origin) {
            // Painel interno permite pedido sem cliente cadastrado (checkbox
            // "Pedido sem cliente") — o Checkout público sempre valida
            // telefone como obrigatório antes de chegar aqui, então nunca
            // cai neste branch.
            $client = blank($checkoutData['phone'] ?? null)
                ? null
                : app(FindOrCreateClient::class)(
                    $checkoutData['phone'],
                    $checkoutData['name'],
                    Arr::only($checkoutData, ['zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state']),
                    $checkoutData['cpf'] ?? null,
                );

            $resolvePriceForCartLine = app(ResolvePriceForCartLine::class);

            $resolvedLines = collect($cartItems)->map(fn (array $item) => [
                'item' => $item,
                'resolved' => $resolvePriceForCartLine($item),
            ]);

            [$promoConsumption, $stockConsumption, $addonConsumption] = $this->ledger->buildConsumptionMaps($resolvedLines);

            $promotions = $this->ledger->lockFlashPromotions($promoConsumption);
            $this->ledger->resetRecurringPromotionsIfNeeded($promotions);
            $this->ledger->assertPromotionsStillActive($promotions);

            $pivots = $this->ledger->lockFlashPromotionProducts($promoConsumption);
            $promoTotalConsumption = $this->ledger->assertPivotAndPerOrderLimits($promoConsumption, $pivots, $promotions);

            $stockControlledProducts = $this->ledger->lockStockControlledProducts($stockConsumption);
            $this->ledger->assertStockAvailable($stockControlledProducts, $stockConsumption);

            $stockControlledAddons = $this->ledger->lockStockControlledAddons($addonConsumption);
            $this->ledger->assertAddonStockAvailable($stockControlledAddons, $addonConsumption);

            $this->ledger->applyDecrements($promotions, $promoTotalConsumption, $pivots, $promoConsumption, $stockControlledProducts, $stockConsumption, $stockControlledAddons, $addonConsumption);

            return $this->createOrderAndItems($client, $resolvedLines, $checkoutData, $origin);
        }, attempts: 3);
    }

    private function createOrderAndItems(?Client $client, $resolvedLines, array $checkoutData, OrderOrigin $origin): Order
    {
        $itemsTotal = $resolvedLines->sum(
            fn (array $line) => ($line['resolved']['unit_price'] + $line['resolved']['addons_total']) * $line['item']['quantity']
        );
        $discountTotal = $resolvedLines->sum(
            fn (array $line) => ($line['resolved']['original_unit_price'] - $line['resolved']['unit_price']) * $line['item']['quantity']
        );

        $deliveryOption = isset($checkoutData['delivery_option_id'])
            ? DeliveryOption::find($checkoutData['delivery_option_id'])
            : null;

        $deliveryFee = 0.0;
        $deliveryZoneId = null;
        $isUnlistedNeighborhood = false;

        if ($deliveryOption && $deliveryOption->requires_address) {
            // RN-37/RN-38: taxa resolvida pelo bairro do cliente, não mais fixa na
            // opção de entrega — pode lançar CheckoutException (bairro fora da área
            // e tenant não atende bairros não configurados), que propaga e reverte
            // a transação, sem criar o pedido (RN-13).
            $resolved = app(ResolveDeliveryFee::class)(
                $deliveryOption,
                $checkoutData['neighborhood'] ?? null,
                $checkoutData['city'] ?? null,
                $itemsTotal,
            );

            $deliveryFee = $resolved['fee'];
            $deliveryZoneId = $resolved['delivery_zone_id'];
            $isUnlistedNeighborhood = $resolved['is_unlisted_neighborhood'];
        } elseif ($deliveryOption) {
            // Opção sem endereço (retirada, consumo no local): taxa fixa da
            // própria opção, sem passar pela resolução por bairro/setor.
            $deliveryFee = (float) $deliveryOption->delivery_fee;

            if ($deliveryOption->min_order_for_free_delivery !== null && $itemsTotal >= $deliveryOption->min_order_for_free_delivery) {
                $deliveryFee = 0.0;
            }
        }

        $grandTotal = round($itemsTotal + $deliveryFee, 2);

        $this->payments->assertPaymentsCoverTotal($checkoutData['payments'], $grandTotal);

        $order = Order::create([
            'order_number' => app(AllocateOrderNumber::class)(CurrentTenant::id()),
            'client_id' => $client?->id,
            'delivery_option_id' => $deliveryOption?->id,
            'delivery_zone_id' => $deliveryZoneId,
            'items_total' => round($itemsTotal, 2),
            'discount_total' => round($discountTotal, 2),
            'delivery_fee' => $deliveryFee,
            'grand_total' => $grandTotal,
            'status' => OrderStatus::Started,
            'delivery_address' => $this->formatAddress($checkoutData),
            'delivery_zip_code' => $checkoutData['zip_code'] ?? null,
            'delivery_street' => $checkoutData['street'] ?? null,
            'delivery_number' => $checkoutData['number'] ?? null,
            'delivery_complement' => $checkoutData['complement'] ?? null,
            'delivery_neighborhood' => $checkoutData['neighborhood'] ?? null,
            'delivery_city' => $checkoutData['city'] ?? null,
            'delivery_state' => $checkoutData['state'] ?? null,
            'is_unlisted_neighborhood' => $isUnlistedNeighborhood,
            'notes' => $checkoutData['notes'] ?? null,
            'origin' => $origin,
            'opened_at' => now(),
        ]);

        foreach ($resolvedLines as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line['item']['product_id'],
                'flash_promotion_id' => $line['resolved']['flash_promotion_id'],
                'quantity' => $line['item']['quantity'],
                'unit_price' => $line['resolved']['unit_price'],
                'original_unit_price' => $line['resolved']['original_unit_price'],
                'note' => $line['item']['note'],
                'flavors' => $line['item']['type'] === 'combo' ? $line['item']['flavor_ids'] : null,
                'addons' => empty($line['item']['addons']) ? null : $line['item']['addons'],
                'addons_total' => $line['resolved']['addons_total'],
            ]);
        }

        $this->payments->createPayments($order, $checkoutData['payments']);

        return $order;
    }

    /**
     * Snapshot em texto livre do endereço estruturado, mantido só para as
     * telas que ainda exibem `delivery_address` como uma linha só (ex.:
     * OrderInfolist) — a lógica de taxa usa sempre os campos estruturados.
     *
     * @param  array{street?: ?string, number?: ?string, complement?: ?string, neighborhood?: ?string, city?: ?string, state?: ?string}  $checkoutData
     */
    private function formatAddress(array $checkoutData): ?string
    {
        $street = trim(($checkoutData['street'] ?? '').' '.($checkoutData['number'] ?? ''));

        if ($checkoutData['complement'] ?? null) {
            $street = trim($street.' - '.$checkoutData['complement']);
        }

        $locality = collect([$checkoutData['neighborhood'] ?? null, $checkoutData['city'] ?? null])
            ->filter()
            ->implode(', ');

        $parts = collect([$street, $locality, $checkoutData['state'] ?? null])->filter();

        return $parts->isEmpty() ? null : $parts->implode(' - ');
    }
}

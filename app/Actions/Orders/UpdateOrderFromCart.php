<?php

namespace App\Actions\Orders;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Actions\Orders\Support\RecordsOrderPayments;
use App\Exceptions\CheckoutException;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Atualiza os itens/entrega/pagamento de um pedido já existente (painel do
 * tenant — colaborador altera um pedido a pedido do cliente). Nunca toca
 * status, timestamps de etapa, origem ou motivo de cancelamento — isso é
 * conteúdo do pedido, não transição de workflow (ver OrderStatusActions).
 *
 * Reverte o consumo de estoque/promoção dos itens ANTIGOS antes de validar
 * e aplicar o consumo dos itens NOVOS, usando a mesma disciplina de lock
 * ordenado entre tabelas de CreateOrderFromCart (via CartStockAndPromotionLedger),
 * pra não introduzir deadlock entre edições concorrentes.
 *
 * Limitação conhecida: a reversão de saldo de promoção por sabor de combo
 * é feita re-resolvendo os itens antigos com ResolvePriceForCartLine (mesma
 * lógica usada pra consumir na criação) — se uma promoção que valia pra um
 * sabor no momento da criação não estiver mais ativa/vigente no momento da
 * edição, a reversão não credita de volta aquela promoção específica (ela
 * já não é mais "resolvível"). É um cenário raro (promoção mudou entre a
 * criação e a edição do mesmo pedido) e não bloqueia a edição.
 */
class UpdateOrderFromCart
{
    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly RecordsOrderPayments $payments,
    ) {}

    /**
     * @param  array<int, array{type: string, product_id: int, flavor_ids: array<int>, quantity: int, note: ?string, addons?: array<int, array{addon_id:int, quantity:int, target:?int}>}>  $cartItems
     * @param  array{phone: string, name: string, cpf?: ?string, zip_code: ?string, street: ?string, number: ?string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string, delivery_option_id: ?int, payments: array<int, array{payment_option_id: int, amount: float, change_for: ?float}>, notes: ?string}  $checkoutData
     */
    public function __invoke(Order $order, array $cartItems, array $checkoutData): Order
    {
        if (empty($cartItems)) {
            throw new CheckoutException('O pedido não pode ficar sem itens.');
        }

        if (empty($checkoutData['payments'])) {
            throw new CheckoutException('Escolha ao menos uma forma de pagamento.');
        }

        return DB::transaction(function () use ($order, $cartItems, $checkoutData) {
            $order->loadMissing('items');

            $resolvePriceForCartLine = app(ResolvePriceForCartLine::class);

            $oldResolvedLines = $this->resolveOldLines($order, $resolvePriceForCartLine);

            $newResolvedLines = collect($cartItems)->map(fn (array $item) => [
                'item' => $item,
                'resolved' => $resolvePriceForCartLine($item),
            ]);

            [$oldPromoConsumption, $oldStockConsumption, $oldAddonConsumption] = $this->ledger->buildConsumptionMaps($oldResolvedLines);
            [$newPromoConsumption, $newStockConsumption, $newAddonConsumption] = $this->ledger->buildConsumptionMaps($newResolvedLines);

            $allPromoConsumption = $this->mergeConsumptionKeys($oldPromoConsumption, $newPromoConsumption);
            $allStockConsumption = $this->mergeConsumptionKeys($oldStockConsumption, $newStockConsumption);
            $allAddonConsumption = $this->mergeConsumptionKeys($oldAddonConsumption, $newAddonConsumption);

            $promotions = $this->ledger->lockFlashPromotions($allPromoConsumption);
            $pivots = $this->ledger->lockFlashPromotionProducts($allPromoConsumption);
            $stockControlledProducts = $this->ledger->lockStockControlledProducts($allStockConsumption);
            $stockControlledAddons = $this->ledger->lockStockControlledAddons($allAddonConsumption);

            // 1) Reverte o consumo dos itens antigos (devolve estoque/saldo).
            $oldPromoTotalConsumption = $this->sumByPromoId($oldPromoConsumption);
            $this->ledger->applyIncrements($promotions, $oldPromoTotalConsumption, $pivots, $oldPromoConsumption, $stockControlledProducts, $oldStockConsumption, $stockControlledAddons, $oldAddonConsumption);

            // 2) Valida e aplica o consumo dos itens novos, com o saldo já revertido.
            // stockControlledProducts/stockControlledAddons foram travados com a
            // UNIÃO antigo+novo — os métodos abaixo esperam só os produtos
            // presentes no mapa de consumo passado, daí o filtro pra manter a
            // mesma disciplina de CreateOrderFromCart (uma coleção, um mapa,
            // chaves 1:1).
            $newStockControlledProducts = $stockControlledProducts->only(array_keys($newStockConsumption));
            $newStockControlledAddons = $stockControlledAddons->only(array_keys($newAddonConsumption));

            $this->ledger->assertPromotionsStillActive($promotions);
            $newPromoTotalConsumption = $this->ledger->assertPivotAndPerOrderLimits($newPromoConsumption, $pivots, $promotions);
            $this->ledger->assertStockAvailable($newStockControlledProducts, $newStockConsumption);
            $this->ledger->assertAddonStockAvailable($newStockControlledAddons, $newAddonConsumption);
            $this->ledger->applyDecrements($promotions, $newPromoTotalConsumption, $pivots, $newPromoConsumption, $newStockControlledProducts, $newStockConsumption, $newStockControlledAddons, $newAddonConsumption);

            // 3) Substitui os itens do pedido (delete + recria, sem diff fino).
            $order->items()->delete();

            foreach ($newResolvedLines as $line) {
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

            // 4) Recalcula totais/entrega/cliente e atualiza só o conteúdo do
            // pedido — status, timestamps de etapa e origem não são tocados.
            $this->recalculateAndPersist($order, $newResolvedLines, $checkoutData);

            return $order->fresh(['items', 'payments']);
        }, attempts: 3);
    }

    /**
     * Reconstrói o carrinho a partir dos OrderItems atuais do pedido (1
     * OrderItem = 1 linha de carrinho no RazelFood) e resolve cada linha de
     * novo via ResolvePriceForCartLine — necessário pra obter o mesmo shape
     * `flavor_prices` por sabor usado no consumo original (o OrderItem só
     * guarda um `flash_promotion_id` como dica de auditoria, não um por
     * sabor). Uma linha cujo produto/sabor não existe mais (removido desde
     * a criação do pedido) é ignorada na reversão — não há saldo confiável
     * pra devolver e não deve bloquear a edição do pedido.
     *
     * @return Collection<int, array{item: array, resolved: array}>
     */
    private function resolveOldLines(Order $order, ResolvePriceForCartLine $resolvePriceForCartLine)
    {
        return $order->items->map(function (OrderItem $item) use ($resolvePriceForCartLine) {
            $cartItem = [
                'type' => $item->flavors ? 'combo' : 'simple',
                'product_id' => $item->product_id,
                'flavor_ids' => $item->flavors ?? [],
                'quantity' => $item->quantity,
                'note' => $item->note,
                'addons' => $item->addons ?? [],
            ];

            try {
                return ['item' => $cartItem, 'resolved' => $resolvePriceForCartLine($cartItem)];
            } catch (ModelNotFoundException|InvalidArgumentException $e) {
                Log::warning('UpdateOrderFromCart: linha antiga não pôde ser re-resolvida para reversão de estoque/promoção, ignorada.', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        })->filter()->values();
    }

    /**
     * @param  array<string|int, int>  $old
     * @param  array<string|int, int>  $new
     * @return array<string|int, int> união das chaves de old+new, valor 0 (só usado pra travar as linhas certas)
     */
    private function mergeConsumptionKeys(array $old, array $new): array
    {
        $merged = [];

        foreach ([...array_keys($old), ...array_keys($new)] as $key) {
            $merged[$key] = 0;
        }

        return $merged;
    }

    /**
     * @param  array<string, int>  $promoConsumption
     * @return array<int, int>
     */
    private function sumByPromoId(array $promoConsumption): array
    {
        $totals = [];

        foreach ($promoConsumption as $key => $quantity) {
            [$promoId] = array_map('intval', explode(':', $key));
            $totals[$promoId] = ($totals[$promoId] ?? 0) + $quantity;
        }

        return $totals;
    }

    private function recalculateAndPersist(Order $order, $resolvedLines, array $checkoutData): void
    {
        // Painel interno permite pedido sem cliente cadastrado (checkbox
        // "Pedido sem cliente") — mesma regra de CreateOrderFromCart.
        $client = blank($checkoutData['phone'] ?? null)
            ? null
            : app(FindOrCreateClient::class)(
                $checkoutData['phone'],
                $checkoutData['name'],
                Arr::only($checkoutData, ['zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state']),
                $checkoutData['cpf'] ?? null,
            );

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
            $deliveryFee = (float) $deliveryOption->delivery_fee;

            if ($deliveryOption->min_order_for_free_delivery !== null && $itemsTotal >= $deliveryOption->min_order_for_free_delivery) {
                $deliveryFee = 0.0;
            }
        }

        $grandTotal = round($itemsTotal + $deliveryFee, 2);

        $this->payments->assertPaymentsCoverTotal($checkoutData['payments'], $grandTotal);

        $order->update([
            'client_id' => $client?->id,
            'delivery_option_id' => $deliveryOption?->id,
            'delivery_zone_id' => $deliveryZoneId,
            'items_total' => round($itemsTotal, 2),
            'discount_total' => round($discountTotal, 2),
            'delivery_fee' => $deliveryFee,
            'grand_total' => $grandTotal,
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
        ]);

        $this->payments->replacePayments($order, $checkoutData['payments']);
    }

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

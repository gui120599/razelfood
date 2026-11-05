<?php

namespace App\Actions\Sales;

use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Exceptions\CheckoutException;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleOrder;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Sales\Money;
use App\Support\Sales\PdvCutoff;
use App\Support\Sales\StandaloneItemPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fecha a conta: cria uma venda FINALIZADA que cobra 1+ pedidos inteiros e/ou
 * itens avulsos e registra o pagamento realmente recebido (RN-VEN-01..03).
 *
 * Tudo numa única transação, sem HTTP externo. Pedidos e itens são relidos com
 * `lockForUpdate` (ordenados por id) e só entram se ainda não tiverem `sale_id`
 * — é isso que impede o mesmo item de entrar em duas vendas, mesmo com dois
 * operadores fechando a mesma conta ao mesmo tempo. Todo cálculo é em
 * centavos inteiros: líquido recebido (valor − troco) tem de bater com o
 * total, centavo a centavo. O troco é calculado AQUI (nunca vem do front) e só
 * pode sair de forma em dinheiro.
 *
 * Itens avulsos reaproveitam o ledger de estoque dos pedidos (mesmo lock de
 * produtos, mesmo `sales_count`) e NÃO consomem saldo de promoção relâmpago.
 */
class FinalizeSale
{
    private const MAX_CARD_BRAND_LENGTH = 60;

    private const MAX_AUTHORIZATION_LENGTH = 100;

    /** Maior valor que cabe em decimal(10,2), em centavos. */
    private const MAX_CENTS = 9_999_999_999;

    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly AllocateSaleNumber $allocateSaleNumber,
        private readonly StandaloneItemPrice $standaloneItemPrice,
    ) {}

    /**
     * @param  array<int, int>  $orderIds
     * @param  array<int, array{product_id: int, quantity: int}>  $standaloneItems
     * @param  array<int, array{payment_option_id: int, amount: float|int|string, card_brand?: ?string, authorization_code?: ?string}>  $payments
     *
     * @throws SaleException
     */
    public function __invoke(array $orderIds, array $standaloneItems, array $payments, ?User $finalizedBy = null): Sale
    {
        $tenant = CurrentTenant::get() ?? throw new SaleException('Nenhum estabelecimento ativo para registrar a venda.');
        $tenantId = $tenant->id;

        // `hasFeature` lê o tenant do cache de resolução (pode estar defasado); a data de
        // corte vem do banco e é a fonte de verdade: sem ela o PDV não está ativo.
        $cutoff = $tenant->hasFeature(FeatureKey::PDV) ? PdvCutoff::for($tenant) : null;

        if ($cutoff === null) {
            throw new SaleException('O PDV não está ativo para este estabelecimento.');
        }
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === [] && $standaloneItems === []) {
            throw new SaleException('Selecione ao menos um pedido ou um item para fechar a conta.');
        }

        return DB::transaction(function () use ($tenantId, $cutoff, $orderIds, $standaloneItems, $payments, $finalizedBy): Sale {
            ['orders' => $orders, 'itemIds' => $orderItemIds] = $this->lockEligibleOrders($orderIds, $cutoff);
            $standalone = $this->prepareStandaloneItems($standaloneItems);

            $ordersGrandCents = $orders->sum(fn (Order $order): int => Money::toCents($order->grand_total));
            $ordersItemsCents = $orders->sum(fn (Order $order): int => Money::toCents($order->items_total));
            $discountCents = $orders->sum(fn (Order $order): int => Money::toCents($order->discount_total));
            $deliveryCents = $orders->sum(fn (Order $order): int => Money::toCents($order->delivery_fee));
            $standaloneCents = $standalone['lines']->sum('total_cents');
            $totalCents = $ordersGrandCents + $standaloneCents;

            $paymentLines = $this->buildPaymentLines($payments, $totalCents);

            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'number' => ($this->allocateSaleNumber)($tenantId),
                'status' => SaleStatus::Finalized,
                'items_total' => Money::fromCents($ordersItemsCents + $standaloneCents),
                'discount_total' => Money::fromCents($discountCents),
                'delivery_fee' => Money::fromCents($deliveryCents),
                'total' => Money::fromCents($totalCents),
                'finalized_at' => now(),
                'finalized_by_user_id' => $finalizedBy?->id,
            ]);

            foreach ($orders as $order) {
                SaleOrder::create([
                    'tenant_id' => $tenantId,
                    'sale_id' => $sale->id,
                    'order_id' => $order->id,
                    'order_label' => $order->displayNumber(),
                    'order_status' => $order->status,
                    'client_name' => $order->client?->name,
                    'order_total' => $order->grand_total,
                ]);
            }

            foreach ($paymentLines as $line) {
                SalePayment::create($line + ['tenant_id' => $tenantId, 'sale_id' => $sale->id]);
            }

            foreach ($standalone['lines'] as $line) {
                SaleItem::create([
                    'tenant_id' => $tenantId,
                    'sale_id' => $sale->id,
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'quantity' => $line['quantity'],
                    'unit_price' => Money::fromCents($line['unit_cents']),
                    'total' => Money::fromCents($line['total_cents']),
                ]);
            }

            if ($orders->isNotEmpty()) {
                // Invariante da Fase 1.0: pedido inteiro — order e itens ganham o vínculo juntos.
                Order::query()->whereIn('id', $orders->modelKeys())->update(['sale_id' => $sale->id]);
                OrderItem::query()->whereKey($orderItemIds)->update(['sale_id' => $sale->id]);
            }

            $this->ledger->applyDecrements(
                collect(), [], collect(), [],
                $standalone['stockControlled'], $standalone['consumption'],
                collect(), [],
            );

            return $sale->load(['payments', 'items', 'saleOrders']);
        }, attempts: 3);
    }

    /**
     * Trava os pedidos (a trava de pedido é o que serializa toda mudança em
     * pedido/itens: Cancel/Update também travam o pedido primeiro) e confere que
     * nenhum item deles já foi cobrado. Os itens são lidos SEM lock de intervalo
     * (`order_items` não tem tenant_id; um lock por `order_id` travaria também o
     * gap do índice e bloquearia o checkout de qualquer tenant) e atualizados por PK.
     *
     * @param  array<int, int>  $orderIds
     * @return array{orders: Collection<int, Order>, itemIds: array<int, int>}
     */
    private function lockEligibleOrders(array $orderIds, Carbon $cutoff): array
    {
        if ($orderIds === []) {
            return ['orders' => collect(), 'itemIds' => []];
        }

        $orders = Order::query()->with('client')->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();

        if ($orders->count() !== count($orderIds)) {
            throw new SaleException('Um ou mais pedidos selecionados não foram encontrados.');
        }

        foreach ($orders as $order) {
            if ($order->status === OrderStatus::Cancelled) {
                throw new SaleException("O pedido {$order->displayNumber()} está cancelado e não pode entrar em uma venda.");
            }

            if ($order->status === OrderStatus::Started) {
                throw new SaleException("O pedido {$order->displayNumber()} ainda não foi aceito e não pode entrar em uma venda.");
            }

            if ($order->created_at->lt($cutoff)) {
                throw new SaleException("O pedido {$order->displayNumber()} é anterior à ativação do PDV e não pode entrar em uma venda.");
            }

            if ($order->sale_id !== null) {
                throw new SaleException("O pedido {$order->displayNumber()} já foi cobrado em outra venda.");
            }
        }

        $items = OrderItem::query()->whereIn('order_id', $orders->modelKeys())->get(['id', 'sale_id']);

        if ($items->contains(fn (OrderItem $item): bool => $item->sale_id !== null)) {
            throw new SaleException('Um item dos pedidos selecionados já foi cobrado em outra venda.');
        }

        return ['orders' => $orders, 'itemIds' => $items->modelKeys()];
    }

    /**
     * Resolve preço/estoque dos itens avulsos e trava os produtos com controle
     * de estoque (mesma disciplina de lock do checkout).
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $standaloneItems
     * @return array{lines: Collection<int, array{product_id: int, product_name: string, quantity: int, unit_cents: int, total_cents: int}>, consumption: array<int, float>, stockControlled: Collection<int, Product>}
     */
    private function prepareStandaloneItems(array $standaloneItems): array
    {
        if ($standaloneItems === []) {
            return ['lines' => collect(), 'consumption' => [], 'stockControlled' => collect()];
        }

        $quantities = [];

        foreach ($standaloneItems as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity < 1) {
                throw new SaleException('A quantidade de cada item avulso deve ser de pelo menos 1.');
            }

            $productId = (int) $item['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        }

        $products = Product::query()->whereIn('id', array_keys($quantities))->get()->keyBy('id');

        if ($products->count() !== count($quantities)) {
            throw new SaleException('Um ou mais produtos avulsos não foram encontrados.');
        }

        $consumption = array_map('floatval', $quantities);
        $stockControlled = $this->ledger->lockStockControlledProducts($consumption);

        try {
            $this->ledger->assertStockAvailable($stockControlled, $consumption);
        } catch (CheckoutException $exception) {
            throw new SaleException($exception->getMessage(), previous: $exception);
        }

        $lines = collect($quantities)->map(function (int $quantity, int $productId) use ($products): array {
            $product = $products->get($productId);
            $unitCents = Money::toCents(($this->standaloneItemPrice)($product));

            return [
                'product_id' => $productId,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_cents' => $unitCents,
                'total_cents' => $unitCents * $quantity,
            ];
        })->values();

        return ['lines' => $lines, 'consumption' => $consumption, 'stockControlled' => $stockControlled];
    }

    /**
     * Valida os pagamentos recebidos e calcula o troco no servidor.
     *
     * @param  array<int, array{payment_option_id: int, amount: float|int|string, card_brand?: ?string, authorization_code?: ?string}>  $payments
     * @return array<int, array<string, mixed>> Linhas prontas para SalePayment (sem tenant_id/sale_id).
     */
    private function buildPaymentLines(array $payments, int $totalCents): array
    {
        if ($payments === []) {
            if ($totalCents > 0) {
                throw new SaleException('Informe ao menos uma forma de pagamento.');
            }

            return [];
        }

        $options = PaymentOption::query()
            ->whereIn('id', array_map(fn (array $payment): int => (int) $payment['payment_option_id'], $payments))
            ->get()
            ->keyBy('id');

        $lines = [];
        $receivedCents = 0;
        $cashCents = 0;

        foreach ($payments as $payment) {
            $option = $options->get((int) $payment['payment_option_id']);

            if ($option === null) {
                throw new SaleException('Forma de pagamento inválida.');
            }

            $amountCents = Money::toCents($payment['amount']);

            if ($amountCents <= 0) {
                throw new SaleException("Informe um valor maior que zero para \"{$option->name}\".");
            }

            if ($amountCents > self::MAX_CENTS) {
                throw new SaleException("O valor informado para \"{$option->name}\" é grande demais.");
            }

            $receivedCents += $amountCents;
            $cashCents += $option->is_cash ? $amountCents : 0;

            $lines[] = [
                'payment_option_id' => $option->id,
                'payment_option_name' => $option->name,
                'is_cash' => (bool) $option->is_cash,
                'amount_cents' => $amountCents,
                'change_cents' => 0,
                'card_brand' => $this->cleanText($payment['card_brand'] ?? null, self::MAX_CARD_BRAND_LENGTH),
                'authorization_code' => $this->cleanText($payment['authorization_code'] ?? null, self::MAX_AUTHORIZATION_LENGTH),
            ];
        }

        if ($receivedCents < $totalCents) {
            throw new SaleException('Pagamento insuficiente: faltam '.Money::format($totalCents - $receivedCents).'.');
        }

        $changeCents = $receivedCents - $totalCents;

        if ($changeCents > $cashCents) {
            throw new SaleException('O valor recebido passa do total em '.Money::format($changeCents).', mas o troco só pode sair de pagamento em dinheiro.');
        }

        // Troco sai das linhas em dinheiro, da última para a primeira.
        foreach (array_reverse(array_keys($lines)) as $index) {
            if ($changeCents === 0) {
                break;
            }

            if (! $lines[$index]['is_cash']) {
                continue;
            }

            $applied = min($changeCents, $lines[$index]['amount_cents']);
            $lines[$index]['change_cents'] = $applied;
            $changeCents -= $applied;
        }

        return array_map(function (array $line): array {
            $line['amount'] = Money::fromCents($line['amount_cents']);
            $line['change_amount'] = Money::fromCents($line['change_cents']);
            unset($line['amount_cents'], $line['change_cents']);

            return $line;
        }, $lines);
    }

    private function cleanText(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}

<?php

namespace App\Actions\Sales;

use App\Actions\Fiscal\ShouldAutoIssue;
use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Actions\Sales\Concerns\DispatchesAutoIssue;
use App\Actions\Sales\Concerns\ManagesSaleOrders;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Exceptions\CheckoutException;
use App\Exceptions\SaleException;
use App\Exceptions\SaleOrdersChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use App\Support\Sales\PaymentLines;
use Illuminate\Support\Facades\DB;

/**
 * Finaliza um rascunho (D-08): é aqui que a venda ganha número, baixa o estoque dos avulsos,
 * valida o pagamento (troco calculado no servidor, centavo a centavo) e dispara a NFC-e
 * automática. Tudo numa transação, sem HTTP externo.
 *
 * Lock (models-models.md): pedidos (por id) -> venda -> produtos -> tenants (número). A venda e os
 * pedidos são relidos sob lock e tudo é revalidado: o rascunho pode ter mudado em outra aba.
 * `orders.sale_id` só é gravado nos pedidos cobrados POR INTEIRO por esta venda (sem item livre
 * nem em outra venda); pedido parcial fica sem ele e o pagamento é derivado dos itens.
 */
class FinalizeOpenSale
{
    use DispatchesAutoIssue;
    use ManagesSaleOrders;

    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly AllocateSaleNumber $allocateSaleNumber,
        private readonly SyncSaleTotals $syncTotals,
        private readonly PaymentLines $paymentLines,
        private readonly ShouldAutoIssue $shouldAutoIssue,
    ) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, ?User $finalizedBy = null): Sale
    {
        OpenSale::pdvCutoff();
        $tenant = CurrentTenant::get();

        $finalized = null;

        for ($attempt = 1; $finalized === null; $attempt++) {
            // Ids lidos FORA da transação: dentro dela o primeiro statement tem de ser o lock dos
            // pedidos e só depois o da venda; qualquer SELECT comum antes disso fixaria um snapshot
            // anterior à espera e esconderia o que outro operador acabou de gravar.
            $candidateIds = OpenSale::orderIdsOf($sale->id);

            try {
                $finalized = DB::transaction(fn (): Sale => $this->finalize($sale, $finalizedBy, $candidateIds), attempts: 3);
            } catch (SaleOrdersChanged $exception) {
                if ($attempt >= 3) {
                    throw new SaleException('O rascunho mudou durante o fechamento. Tente finalizar de novo.', previous: $exception);
                }
            }
        }

        $this->dispatchAutoIssue($tenant, $finalized);

        return $finalized;
    }

    /**
     * @param  list<int>  $candidateIds
     *
     * @throws SaleOrdersChanged
     */
    private function finalize(Sale $sale, ?User $finalizedBy, array $candidateIds): Sale
    {
        // 1) Pedidos primeiro (travados por id), como PRIMEIRO statement da transação.
        $orders = $candidateIds === []
            ? collect()
            : Order::query()->whereIn('id', $candidateIds)->orderBy('id')->lockForUpdate()->get();

        // 2) Venda sob lock, ainda aberta; depois dos locks o conjunto de pedidos é relido.
        $locked = OpenSale::lock($sale);

        $currentIds = OpenSale::orderIdsOf($locked->id);

        if (array_diff($currentIds, $candidateIds) !== []) {
            throw new SaleOrdersChanged;
        }

        $orders = $orders->whereIn('id', $currentIds)->each(fn (Order $order) => $order->load('client'))->values();

        foreach ($orders as $order) {
            if ($order->status === OrderStatus::Cancelled) {
                throw new SaleException("O pedido {$order->displayNumber()} está cancelado e não pode ser cobrado. Tire-o da venda.");
            }
        }

        $standalone = SaleItem::query()->where('sale_id', $locked->id)->orderBy('id')->get();

        if ($orders->isEmpty() && $standalone->isEmpty()) {
            throw new SaleException('Lance ao menos um pedido ou um item para finalizar a venda.');
        }

        // 3) Estoque dos avulsos (produtos travados em ordem), só agora baixa.
        $consumption = [];

        foreach ($standalone as $item) {
            if ($item->product_id !== null) {
                $consumption[$item->product_id] = ($consumption[$item->product_id] ?? 0) + (float) $item->quantity;
            }
        }

        ksort($consumption);
        $stockControlled = $this->ledger->lockStockControlledProducts($consumption);

        try {
            $this->ledger->assertStockAvailable($stockControlled, $consumption);
        } catch (CheckoutException $exception) {
            throw new SaleException($exception->getMessage(), previous: $exception);
        }

        // 4) Totais definitivos e pagamento (troco no servidor).
        ($this->syncTotals)($locked);
        $totalCents = Money::toCents($locked->total);

        $inputs = SalePayment::query()->where('sale_id', $locked->id)->orderBy('id')->get()
            ->map(fn (SalePayment $payment): array => [
                'payment_option_id' => $payment->payment_option_id,
                'amount' => $payment->amount,
                'card_brand' => $payment->card_brand_id === null ? $payment->card_brand : null,
                'card_brand_id' => $payment->card_brand_id,
                'authorization_code' => $payment->authorization_code,
            ])->all();

        $lines = $this->paymentLines->build($inputs, $totalCents);

        SalePayment::query()->where('sale_id', $locked->id)->delete();

        foreach ($lines as $line) {
            SalePayment::create($line + ['tenant_id' => $locked->tenant_id, 'sale_id' => $locked->id]);
        }

        // 5) Número, status e vínculos.
        $locked->update([
            'number' => ($this->allocateSaleNumber)($locked->tenant_id),
            'status' => SaleStatus::Finalized,
            'finalized_at' => now(),
            'finalized_by_user_id' => $finalizedBy?->id,
        ]);

        foreach ($orders as $order) {
            $this->syncSaleOrderLine($locked, $order);
        }

        $fullyCharged = $orders->filter(
            fn (Order $order): bool => ! OrderItem::query()->where('order_id', $order->id)
                ->where(fn ($query) => $query->whereNull('sale_id')->orWhere('sale_id', '!=', $locked->id))
                ->exists()
        );

        if ($fullyCharged->isNotEmpty()) {
            Order::query()->whereIn('id', $fullyCharged->modelKeys())->update(['sale_id' => $locked->id]);
        }

        $this->ledger->applyDecrements(
            collect(), [], collect(), [],
            $stockControlled, $consumption,
            collect(), [],
        );

        return $locked->load(['payments', 'items', 'saleOrders']);
    }
}

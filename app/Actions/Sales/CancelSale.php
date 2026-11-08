<?php

namespace App\Actions\Sales;

use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Actions\Sales\Concerns\ManagesSaleOrders;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Exceptions\SaleOrdersChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\SaleOrder;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use App\Support\Sales\SaleCancellationGuard;
use Illuminate\Support\Facades\DB;

/**
 * Cancela uma venda finalizada OU um rascunho (RN-VEN-05): os itens dos pedidos voltam a
 * "a receber" (sale_id nulo; no rascunho é a liberação da reserva), o estoque dos itens AVULSOS é devolvido (o dos pedidos não —
 * pedido já produzido consumiu) e os pagamentos ficam como histórico da venda
 * cancelada. O gancho `SaleCancellationGuard` permite bloquear o cancelamento
 * (RN-VEN-06: documento fiscal vivo, Fase 1.2).
 */
class CancelSale
{
    use ManagesSaleOrders;

    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly SaleCancellationGuard $guard,
    ) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, string $note, ?User $cancelledBy = null): Sale
    {
        $note = trim($note);

        if ($note === '') {
            throw new SaleException('Informe o motivo do cancelamento da venda.');
        }

        if (mb_strlen($note) > 255) {
            throw new SaleException('O motivo do cancelamento pode ter no máximo 255 caracteres.');
        }

        for ($attempt = 1; ; $attempt++) {
            // Ids dos pedidos lidos FORA da transação (o primeiro statement dela é o lock dos
            // pedidos, depois o da venda); o conjunto é conferido de novo depois dos locks.
            $candidateIds = OpenSale::orderIdsOf($sale->getKey());

            try {
                return DB::transaction(fn (): Sale => $this->cancel($sale, $note, $cancelledBy, $candidateIds), attempts: 3);
            } catch (SaleOrdersChanged $exception) {
                if ($attempt >= 3) {
                    throw new SaleException('A venda mudou durante o cancelamento. Tente de novo.', previous: $exception);
                }
            }
        }
    }

    /**
     * @param  list<int>  $orderIds
     *
     * @throws SaleOrdersChanged
     */
    private function cancel(Sale $sale, string $note, ?User $cancelledBy, array $orderIds): Sale
    {
        if ($orderIds !== []) {
            Order::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->first()
            ?? throw new SaleException('Venda não encontrada.');

        if (! $locked->status->canBeCancelled()) {
            throw new SaleException("A venda {$locked->displayNumber()} já está cancelada.");
        }

        if (($reason = $this->guard->blockedReason($locked)) !== null) {
            throw new SaleException($reason);
        }

        if (array_diff(OpenSale::orderIdsOf($locked->id), $orderIds) !== []) {
            throw new SaleOrdersChanged;
        }

        if ($orderIds !== []) {
            Order::query()->where('sale_id', $locked->id)->whereIn('id', $orderIds)->update(['sale_id' => null]);
            // Por PK: um UPDATE por order_id travaria o intervalo do índice (ver FinalizeSale).
            OrderItem::query()->whereKey(OrderItem::query()->where('sale_id', $locked->id)->pluck('id')->all())->update(['sale_id' => null]);
        }

        // Rascunho nunca baixou estoque: só a venda finalizada devolve o dos avulsos.
        $consumption = [];

        if ($locked->status === SaleStatus::Finalized) {
            foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                $consumption[$item->product_id] = ($consumption[$item->product_id] ?? 0) + (float) $item->quantity;
            }

            ksort($consumption);

            $this->ledger->applyIncrements(
                collect(), [], collect(), [],
                $this->ledger->lockStockControlledProducts($consumption), $consumption,
                collect(), [],
            );
        }

        $locked->update([
            'status' => SaleStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $cancelledBy?->id,
            'cancellation_note' => $note,
            'pending_cancellation_note' => null,
            'pending_cancellation_by_user_id' => null,
            'pending_cancellation_at' => null,
        ]);

        // O frete que esta venda cobrava de um pedido passa para outra venda ABERTA com itens dele.
        foreach (SaleOrder::query()->where('sale_id', $locked->id)->where('delivery_fee_charged', '>', 0)->orderBy('order_id')->get() as $line) {
            $this->transferFreight($locked, (int) $line->order_id, Money::toCents($line->delivery_fee_charged));
        }

        return $locked->load(['payments', 'items']);
    }
}

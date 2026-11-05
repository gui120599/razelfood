<?php

namespace App\Actions\Sales;

use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\Sales\SaleCancellationGuard;
use Illuminate\Support\Facades\DB;

/**
 * Cancela uma venda finalizada (RN-VEN-05): os pedidos voltam a "a receber"
 * (sale_id nulo), o estoque dos itens AVULSOS é devolvido (o dos pedidos não —
 * pedido já produzido consumiu) e os pagamentos ficam como histórico da venda
 * cancelada. O gancho `SaleCancellationGuard` permite bloquear o cancelamento
 * (RN-VEN-06: documento fiscal vivo, Fase 1.2).
 */
class CancelSale
{
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

        return DB::transaction(function () use ($sale, $note, $cancelledBy): Sale {
            $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->first()
                ?? throw new SaleException('Venda não encontrada.');

            if (! $locked->status->canBeCancelled()) {
                throw new SaleException("A venda {$locked->displayNumber()} já está cancelada.");
            }

            if (($reason = $this->guard->blockedReason($locked)) !== null) {
                throw new SaleException($reason);
            }

            $orderIds = Order::query()->where('sale_id', $locked->id)->orderBy('id')->lockForUpdate()->pluck('id')->all();

            if ($orderIds !== []) {
                Order::query()->whereIn('id', $orderIds)->update(['sale_id' => null]);
                // Por PK: um UPDATE por order_id travaria o intervalo do índice (ver FinalizeSale).
                OrderItem::query()->whereKey(OrderItem::query()->where('sale_id', $locked->id)->pluck('id')->all())->update(['sale_id' => null]);
            }

            $consumption = [];

            foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                $consumption[$item->product_id] = ($consumption[$item->product_id] ?? 0) + (float) $item->quantity;
            }

            $this->ledger->applyIncrements(
                collect(), [], collect(), [],
                $this->ledger->lockStockControlledProducts($consumption), $consumption,
                collect(), [],
            );

            $locked->update([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $cancelledBy?->id,
                'cancellation_note' => $note,
            ]);

            return $locked->load(['payments', 'items']);
        }, attempts: 3);
    }
}

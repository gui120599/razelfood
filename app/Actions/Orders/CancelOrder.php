<?php

namespace App\Actions\Orders;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * RN-31: cancelamento exige motivo categorizado. "Rejeitar" (RF-26) é a mesma
 * ação, só com rótulo contextual diferente quando o pedido ainda está em
 * "started" — não existe status técnico próprio pra rejeitado.
 */
class CancelOrder
{
    public function __invoke(Order $order, CancellationReason $reason, ?User $cancelledBy): Order
    {
        if (! $order->status->canBeCancelled()) {
            throw new OrderTransitionException("Pedido #{$order->id} não pode mais ser cancelado (está \"{$order->status->label()}\").");
        }

        return DB::transaction(function () use ($order, $reason, $cancelledBy): Order {
            $previous = $order->status;

            $order->status = OrderStatus::Cancelled;
            $order->cancellation_reason = $reason;
            $order->cancelled_by_user_id = $cancelledBy?->id;
            $order->cancelled_at = now();
            $order->save();

            $order->statusHistories()->create([
                'status_from' => $previous,
                'status_to' => OrderStatus::Cancelled,
                'user_id' => $cancelledBy?->id,
                'note' => $reason->label(),
            ]);

            return $order;
        });
    }
}

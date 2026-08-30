<?php

namespace App\Actions\Orders;

use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdvanceOrderStatus
{
    public function __invoke(Order $order, ?User $actingUser = null): Order
    {
        $next = $order->status->next($order->usesInTransitStage());

        if ($next === null) {
            throw new OrderTransitionException("Pedido #{$order->id} não tem próxima etapa a partir de \"{$order->status->label()}\".");
        }

        return DB::transaction(function () use ($order, $next, $actingUser): Order {
            $previous = $order->status;

            $order->status = $next;
            $order->{$next->timestampColumn()} = now();
            $order->save();

            $order->statusHistories()->create([
                'status_from' => $previous,
                'status_to' => $next,
                'user_id' => $actingUser?->id,
            ]);

            return $order;
        });
    }
}

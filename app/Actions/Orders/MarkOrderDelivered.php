<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MarkOrderDelivered
{
    public function __invoke(Order $order, ?User $actingUser = null): Order
    {
        if ($order->status !== OrderStatus::InTransit) {
            throw new OrderTransitionException("Pedido #{$order->id} só pode ser confirmado como entregue a partir de \"Em Transporte\" (está \"{$order->status->label()}\").");
        }

        return DB::transaction(function () use ($order, $actingUser): Order {
            $order->status = OrderStatus::Delivered;
            $order->delivered_at = now();
            $order->save();

            $order->statusHistories()->create([
                'status_from' => OrderStatus::InTransit,
                'status_to' => OrderStatus::Delivered,
                'user_id' => $actingUser?->id,
            ]);

            return $order;
        });
    }
}

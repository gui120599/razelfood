<?php

namespace App\Actions\Orders;

use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\User;

/**
 * Atribui um entregador específico a um pedido (seção 5/7/16 da spec da
 * Central de Pedidos). Independente da máquina de estados de OrderStatus —
 * não muda o status do pedido, só o campo assigned_delivery_user_id.
 */
class AssignDeliveryUser
{
    public function __invoke(Order $order, User $deliveryUser, User $actingUser): Order
    {
        if ($deliveryUser->tenant_id !== $order->tenant_id) {
            throw new OrderTransitionException("{$deliveryUser->name} não pertence a este estabelecimento.");
        }

        if (! $deliveryUser->hasRole('Entregador')) {
            throw new OrderTransitionException("{$deliveryUser->name} não tem o papel de Entregador.");
        }

        $order->assigned_delivery_user_id = $deliveryUser->id;
        $order->save();

        return $order;
    }
}

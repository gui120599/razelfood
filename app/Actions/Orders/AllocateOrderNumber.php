<?php

namespace App\Actions\Orders;

use Illuminate\Support\Facades\DB;

/**
 * Aloca o próximo número de pedido do tenant (sequência contínua por
 * estabelecimento, sem reset). Deve rodar SEMPRE dentro da transação de
 * CreateOrderFromCart — `attempts: 3` daquela transação já cobre deadlock
 * do lock. Trava a linha do tenant, incrementa o contador e devolve o
 * número novo.
 */
class AllocateOrderNumber
{
    public function __invoke(int $tenantId): int
    {
        $next = (int) DB::table('tenants')
            ->where('id', $tenantId)
            ->lockForUpdate()
            ->value('orders_sequence') + 1;

        DB::table('tenants')->where('id', $tenantId)->update(['orders_sequence' => $next]);

        return $next;
    }
}

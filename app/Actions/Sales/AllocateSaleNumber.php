<?php

namespace App\Actions\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Aloca o próximo número de venda do tenant (sequência contínua por
 * estabelecimento, sem reset). Espelho de AllocateOrderNumber: deve rodar
 * SEMPRE dentro da transação de FinalizeSale. Trava a linha do tenant,
 * incrementa o contador e devolve o número novo.
 */
class AllocateSaleNumber
{
    public function __invoke(int $tenantId): int
    {
        $next = (int) DB::table('tenants')
            ->where('id', $tenantId)
            ->lockForUpdate()
            ->value('sales_sequence') + 1;

        DB::table('tenants')->where('id', $tenantId)->update(['sales_sequence' => $next]);

        return $next;
    }
}

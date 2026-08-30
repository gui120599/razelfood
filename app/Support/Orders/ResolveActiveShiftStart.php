<?php

namespace App\Support\Orders;

use App\Models\BusinessHour;
use Carbon\Carbon;

/**
 * Resolve o início do turno de funcionamento mais recente já iniciado, entre
 * todos os turnos ativos do tenant atual (podem existir vários por dia, ex.
 * almoço + jantar — ver ManageBusinessHours). Usado para cortar as colunas
 * Finalizados/Cancelados da Central de Pedidos pelo turno em vez de um
 * número fixo de registros ou "hoje" fixo: quando um novo turno começa, o
 * board reseta sozinho, e o que ficou pra trás permanece no Histórico de
 * Pedidos (OrderResource), que não é afetado por este corte.
 *
 * O "início" de um turno é sempre weekday + opens_at, mesmo quando o turno
 * cruza a meia-noite (ex. 22h-02h) — não precisa do tratamento especial de
 * CheckBusinessHours::isOpenAt(), que resolve "está aberto agora", um
 * problema diferente de "quando começou o turno mais recente".
 */
class ResolveActiveShiftStart
{
    public function __invoke(?Carbon $now = null): Carbon
    {
        $now = $now ?? now();
        $hours = BusinessHour::where('is_active', true)->get();

        if ($hours->isEmpty()) {
            return $now->copy()->startOfDay();
        }

        $latestStart = null;

        foreach ($hours as $hour) {
            for ($daysAgo = 0; $daysAgo <= 7; $daysAgo++) {
                $day = $now->copy()->subDays($daysAgo);

                if ($day->dayOfWeek !== $hour->weekday) {
                    continue;
                }

                $start = $day->copy()->setTimeFrom($hour->opens_at);

                if ($start->greaterThan($now)) {
                    continue;
                }

                if ($latestStart === null || $start->greaterThan($latestStart)) {
                    $latestStart = $start;
                }

                break;
            }
        }

        return $latestStart ?? $now->copy()->startOfDay();
    }
}

<?php

namespace App\Support\Orders;

use App\Enums\OrderUrgencyLevel;

/**
 * Classe pura (sem I/O): o nível de atraso depende dos limiares do tenant,
 * não do pedido isolado — quem chama resolve os limiares uma única vez
 * (ex.: Kitchen::ordersByStatus()) e reaproveita para todos os cards do
 * board, evitando carregar a relação `tenant` linha a linha.
 */
class OrderUrgencyResolver
{
    public function resolve(?int $minutesInStage, int $attentionAfterMinutes, int $lateAfterMinutes): OrderUrgencyLevel
    {
        return match (true) {
            $minutesInStage === null => OrderUrgencyLevel::Normal,
            $minutesInStage >= $lateAfterMinutes => OrderUrgencyLevel::Late,
            $minutesInStage >= $attentionAfterMinutes => OrderUrgencyLevel::Attention,
            default => OrderUrgencyLevel::Normal,
        };
    }
}

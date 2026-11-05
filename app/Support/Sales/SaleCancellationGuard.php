<?php

namespace App\Support\Sales;

use App\Models\Sale;

/**
 * Gancho que decide se uma venda pode ser cancelada além das regras da própria
 * venda. Na Fase 1.0 nunca bloqueia; a Fase 1.2 (NFC-e) passa a devolver o
 * motivo quando houver documento fiscal vivo (RN-VEN-06: cancelar a nota antes
 * de cancelar a venda). Resolvido pelo container em CancelSale.
 */
class SaleCancellationGuard
{
    /**
     * @return string|null Motivo (pt-BR, seguro para exibir) ou null se pode cancelar.
     */
    public function blockedReason(Sale $sale): ?string
    {
        return null;
    }
}

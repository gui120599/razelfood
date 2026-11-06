<?php

namespace App\Support\Sales;

use App\Models\Sale;

/**
 * Gancho que decide se uma venda pode ser cancelada além das regras da própria
 * venda. Com NFC-e VIVA (emitida, em processamento ou em cancelamento) a venda
 * não pode ser cancelada (RN-VEN-06: cancelar a nota antes de cancelar a venda;
 * o cancelamento da nota chega na Fase 1.3). Resolvido pelo container em CancelSale.
 */
class SaleCancellationGuard
{
    /**
     * @return string|null Motivo (pt-BR, seguro para exibir) ou null se pode cancelar.
     */
    public function blockedReason(Sale $sale): ?string
    {
        if ($sale->hasLiveFiscalDocument()) {
            return 'Esta venda tem uma NFC-e emitida ou em processamento e não pode ser cancelada. Cancele a nota fiscal antes.';
        }

        return null;
    }
}

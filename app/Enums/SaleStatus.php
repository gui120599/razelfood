<?php

namespace App\Enums;

/**
 * Estado da venda (Fase 1.0). A venda nasce Finalizada (fechamento num passo
 * só); "Aberta" — pagamento parcial acumulado, necessário só para a Stone —
 * entra como case novo na Fase 2.3.
 */
enum SaleStatus: string
{
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Finalized => 'Finalizada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Finalized => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function canBeCancelled(): bool
    {
        return $this === self::Finalized;
    }
}

<?php

namespace App\Enums;

/**
 * Estado da venda. "Aberta" é o rascunho da tela Nova Venda (D-08): salvo desde o
 * 1º lançamento, sem número, sem baixa de estoque e sem efeito fiscal até finalizar.
 * A Fase 2.3 (Stone) reaproveita o mesmo estado para pagamento parcial.
 */
enum SaleStatus: string
{
    case Open = 'open';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Finalized => 'Finalizada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Finalized => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function canBeCancelled(): bool
    {
        return $this === self::Finalized || $this === self::Open;
    }
}

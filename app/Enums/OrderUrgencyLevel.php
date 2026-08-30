<?php

namespace App\Enums;

enum OrderUrgencyLevel: string
{
    case Normal = 'normal';
    case Attention = 'attention';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'No prazo',
            self::Attention => 'Atenção',
            self::Late => 'Atrasado',
        };
    }

    /**
     * Cores sutis (bordas finas, não preenchimento vibrante) — seção 9 da spec
     * pede alerta de atraso claro, mas sem exagero visual.
     */
    public function color(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Attention => 'warning',
            self::Late => 'danger',
        };
    }
}

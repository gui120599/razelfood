<?php

namespace App\Enums;

/**
 * Quem decidiu emitir: o fechamento da venda (forma com emissão automática)
 * ou uma pessoa na tela da venda.
 */
enum FiscalDocumentDecision: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automática',
            self::Manual => 'Manual',
        };
    }
}

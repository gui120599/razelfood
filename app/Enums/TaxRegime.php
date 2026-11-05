<?php

namespace App\Enums;

/**
 * Regime tributário do emitente. A v1 da NFC-e suporta SOMENTE o Simples
 * Nacional (D-07: CSOSN); os demais existem no enum para a tela explicar por
 * que são recusados, em vez de sumirem da lista.
 */
enum TaxRegime: string
{
    case SimplesNacional = 'simples_nacional';
    case SimplesNacionalSublimite = 'simples_nacional_sublimite';
    case LucroPresumido = 'lucro_presumido';
    case LucroReal = 'lucro_real';

    public function label(): string
    {
        return match ($this) {
            self::SimplesNacional => 'Simples Nacional',
            self::SimplesNacionalSublimite => 'Simples Nacional (excesso de sublimite)',
            self::LucroPresumido => 'Lucro Presumido',
            self::LucroReal => 'Lucro Real',
        };
    }

    public function isSupported(): bool
    {
        return $this === self::SimplesNacional;
    }

    public static function unsupportedMessage(): string
    {
        return 'Nesta versão só emitimos NFC-e para empresas do Simples Nacional. Para outros regimes, fale com a Razel Tec.';
    }
}

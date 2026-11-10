<?php

namespace App\Support\Fiscal;

/**
 * Rótulo ASCII da fração de um sabor na descrição da NFC-e ("1/2", "1/3"...). O
 * caractere de fração (½) fica fora do Latin-1 e pode ser recusado pela SEFAZ.
 * Fora das frações usuais, cai no percentual ("40%").
 */
final class FlavorFraction
{
    /** @var array<string, float> */
    private const KNOWN = ['1/4' => 0.25, '1/3' => 1 / 3, '1/2' => 0.5, '2/3' => 2 / 3, '3/4' => 0.75];

    public static function label(float $percent): string
    {
        $share = $percent / 100;

        foreach (self::KNOWN as $label => $value) {
            // Um ponto percentual: o rateio padrão 33/33/34 precisa ler como terços (34 fica a 0,67 ponto de 1/3).
            if (abs($share - $value) <= 0.01) {
                return $label;
            }
        }

        return rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',').'%';
    }

    /**
     * Rótulo da fatia nas telas de pedido ("MEIA", "1/3"…): metade lê por extenso, como na tela de origem;
     * as demais frações seguem {@see label()}. Não é usado na NFC-e, que mantém o "1/2" em ASCII.
     */
    public static function sliceLabel(float $percent): string
    {
        return abs($percent - 50) < 0.5 ? 'MEIA' : self::label($percent);
    }
}

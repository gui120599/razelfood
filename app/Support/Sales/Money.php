<?php

namespace App\Support\Sales;

/**
 * Aritmética monetária da venda em CENTAVOS inteiros: evita ruído de float
 * ("pago − troco = total" tem de bater centavo a centavo).
 */
final class Money
{
    public static function toCents(float|int|string|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * Valor decimal com 2 casas e ponto (formato aceito pelas colunas decimal).
     */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Formato de exibição em reais: "R$ 1.234,56".
     */
    public static function format(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}

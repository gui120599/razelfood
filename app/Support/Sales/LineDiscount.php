<?php

namespace App\Support\Sales;

/**
 * Regra do desconto em reais por linha ("Desc. R$"): escala com a quantidade (preserva o desconto
 * POR UNIDADE, como na origem) e nunca passa do valor da linha.
 */
final class LineDiscount
{
    public static function scale(int $discountCents, int $oldQuantity, int $newQuantity, int $newGrossCents): int
    {
        if ($discountCents <= 0 || $oldQuantity < 1) {
            return 0;
        }

        return min($newGrossCents, (int) round($discountCents * $newQuantity / $oldQuantity));
    }
}

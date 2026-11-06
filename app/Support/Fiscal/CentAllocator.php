<?php

namespace App\Support\Fiscal;

use InvalidArgumentException;

/**
 * Divide um valor em centavos entre N partes de forma igual, sem perder nem
 * criar centavo: o resto vai, um a um, para as primeiras partes (RN-FIS-07).
 */
final class CentAllocator
{
    /**
     * @return list<int>
     */
    public static function split(int $totalCents, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('É preciso ao menos uma parte para ratear.');
        }

        if ($totalCents < 0) {
            throw new InvalidArgumentException('Não é possível ratear valor negativo.');
        }

        $base = intdiv($totalCents, $parts);
        $remainder = $totalCents % $parts;

        return array_map(
            fn (int $index): int => $base + ($index < $remainder ? 1 : 0),
            range(0, $parts - 1),
        );
    }
}

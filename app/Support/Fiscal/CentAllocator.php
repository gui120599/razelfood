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

    /**
     * Divide um valor em centavos proporcionalmente aos pesos (maior resto), sem perder nem criar
     * centavo: a soma das partes é sempre `$totalCents`. Pesos zerados recebem 0; se todos forem
     * zero, o valor não tem onde ser rateado.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function proportional(int $totalCents, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('É preciso ao menos uma parte para ratear.');
        }

        if ($totalCents < 0) {
            throw new InvalidArgumentException('Não é possível ratear valor negativo.');
        }

        $weightSum = array_sum($weights);

        if ($weightSum <= 0) {
            if ($totalCents === 0) {
                return array_fill(0, count($weights), 0);
            }

            throw new InvalidArgumentException('Não há valor nas linhas para ratear o desconto.');
        }

        $parts = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $exact = $totalCents * max(0, $weight);
            $parts[$index] = intdiv($exact, $weightSum);
            $remainders[$index] = $exact % $weightSum;
        }

        $left = $totalCents - array_sum($parts);

        // Maior resto primeiro; empate fica com a linha mais antiga (índice menor).
        uksort($remainders, fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_keys($remainders) as $index) {
            if ($left === 0) {
                break;
            }

            if ($weights[$index] > 0) {
                $parts[$index]++;
                $left--;
            }
        }

        ksort($parts);

        return array_values($parts);
    }
}

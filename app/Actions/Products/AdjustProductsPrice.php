<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Ajuste de preço em massa dos produtos selecionados na listagem.
 *
 * `mode`:
 * - `set`      — define `$value` como preço.
 * - `percent`  — soma/subtrai `$value`% do preço atual (conforme `$direction`).
 * - `amount`   — soma/subtrai R$ `$value` do preço atual (conforme `$direction`).
 *
 * `direction` (`increase`|`decrease`) só é usado em `percent`/`amount`.
 * O preço nunca fica negativo (piso em R$ 0,00). Se `applyToPromotional`,
 * o mesmo ajuste é aplicado a `promotional_price` quando ele existe.
 */
class AdjustProductsPrice
{
    /**
     * @param  Collection<int, Product>  $products
     * @return int quantos produtos foram atualizados
     */
    public function __invoke(
        Collection $products,
        string $mode,
        float $value,
        string $direction = 'increase',
        bool $applyToPromotional = false,
    ): int {
        $updated = 0;

        foreach ($products as $product) {
            $product->price = $this->apply((float) $product->price, $mode, $value, $direction);

            if ($applyToPromotional && $product->promotional_price !== null) {
                $product->promotional_price = $this->apply((float) $product->promotional_price, $mode, $value, $direction);
            }

            $product->save();
            $updated++;
        }

        return $updated;
    }

    private function apply(float $current, string $mode, float $value, string $direction): float
    {
        $signed = $direction === 'decrease' ? -$value : $value;

        $new = match ($mode) {
            'set' => $value,
            'percent' => $current * (1 + $signed / 100),
            'amount' => $current + $signed,
            default => throw new InvalidArgumentException("Modo de ajuste inválido: {$mode}."),
        };

        return max(0.0, round($new, 2));
    }
}

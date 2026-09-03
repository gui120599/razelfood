<?php

namespace App\Actions\Products;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Vincula um mesmo produto-brinde (RN-53) a vários produtos principais de uma
 * vez, a partir da listagem de produtos. Se o vínculo já existir, atualiza a
 * quantidade / o estado ativo / as quantidades de sabores. Um produto nunca é
 * brinde de si mesmo (silenciosamente pulado). `tenant_id` do pivot é
 * preenchido pelo hook de BelongsToTenant em ProductGift::creating.
 */
class AttachGiftToProducts
{
    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int>|null  $flavorCounts  quantidades de sabores em que o brinde é oferecido; vazio/null = todas
     * @return int quantos produtos ficaram com o brinde vinculado
     */
    public function __invoke(
        Collection $products,
        int $giftProductId,
        int $quantity,
        bool $isActive,
        ?array $flavorCounts = null,
    ): int {
        $flavorCounts = empty($flavorCounts)
            ? null
            : collect($flavorCounts)->map(fn ($count): int => (int) $count)->sort()->values()->all();

        $pivot = [
            'quantity' => max(1, $quantity),
            'is_active' => $isActive,
            'flavor_counts' => $flavorCounts,
        ];

        $attached = 0;

        foreach ($products as $product) {
            if ($product->id === $giftProductId) {
                continue;
            }

            $product->gifts()->syncWithoutDetaching([$giftProductId => $pivot]);
            $attached++;
        }

        return $attached;
    }
}

<?php

namespace App\Actions\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Replica (copia, mantendo os originais) uma lista de produtos para outra
 * categoria/subcategoria. Cada cópia entra no fim da ordem de exibição da
 * categoria de destino, zera `sales_count` e leva junto os adicionais
 * vinculados (pivot `product_addon`) e os brindes vinculados (pivot
 * `product_gift`, RN-53) — não copia vínculos de promoção relâmpago (são
 * específicos de cada campanha).
 */
class ReplicateProductsToCategory
{
    /**
     * @param  Collection<int, Product>  $products
     * @return int quantos produtos foram criados
     */
    public function __invoke(Collection $products, Category $target): int
    {
        $displayOrder = (int) Product::withTrashed()
            ->where('category_id', $target->id)
            ->max('display_order');

        $created = 0;

        foreach ($products as $product) {
            $product->loadMissing(['addons', 'gifts']);

            $copy = $product->replicate(['sales_count']);
            $copy->category_id = $target->id;
            $copy->display_order = ++$displayOrder;
            $copy->sales_count = 0;
            $copy->save();

            foreach ($product->addons as $addon) {
                $copy->addons()->attach($addon->id, [
                    'price' => $addon->pivot->price,
                    'max_quantity' => $addon->pivot->max_quantity,
                ]);
            }

            foreach ($product->gifts as $gift) {
                $copy->gifts()->attach($gift->id, [
                    'quantity' => $gift->pivot->quantity,
                    'is_active' => $gift->pivot->is_active,
                    'flavor_counts' => $gift->pivot->flavor_counts,
                ]);
            }

            $created++;
        }

        return $created;
    }
}

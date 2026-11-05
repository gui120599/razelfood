<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Support\Fiscal\ProductFiscalData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Define os dados fiscais dos produtos selecionados na listagem. Aplica SÓ os
 * campos preenchidos em `$data` — campo vazio mantém o que cada produto já tem
 * (para "limpar" um campo, edite o produto).
 */
class SetProductsFiscalData
{
    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, mixed>  $data
     * @return int quantos produtos foram atualizados
     */
    public function __invoke(Collection $products, array $data): int
    {
        $values = collect($data)
            ->only(ProductFiscalData::INHERITABLE_FIELDS)
            ->filter(fn (mixed $value): bool => filled($value))
            ->all();

        if ($values === []) {
            return 0;
        }

        DB::transaction(function () use ($products, $values): void {
            foreach ($products as $product) {
                $product->update($values);
            }
        });

        return $products->count();
    }
}

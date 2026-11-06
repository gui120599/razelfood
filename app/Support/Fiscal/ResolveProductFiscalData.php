<?php

namespace App\Support\Fiscal;

use App\Models\Product;
use App\Models\Scopes\TenantScope;

/**
 * Resolve, campo a campo, o dado fiscal de um produto: o que o produto
 * preencheu vence; o que faltar vem da categoria e, depois, da categoria-pai
 * (subcategoria tem no máximo 1 nível de pai). Espera `category.parent`
 * carregados em listagens (evita N+1); sem eles, carrega sob demanda.
 */
class ResolveProductFiscalData
{
    public function __invoke(Product $product): ProductFiscalData
    {
        // Sem o escopo de tenant: a resolução vale para o tenant do produto, mesmo que o
        // CurrentTenant seja outro (job, comando, painel central).
        $product->loadMissing(['category' => fn ($query) => $query->withoutGlobalScope(TenantScope::class), 'category.parent' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)]);

        $sources = array_values(array_filter([$product, $product->category, $product->category?->parent]));

        $pick = function (string $field) use ($sources): ?string {
            foreach ($sources as $source) {
                $value = $source->{$field};

                if (filled($value)) {
                    return (string) $value;
                }
            }

            return null;
        };

        return new ProductFiscalData(
            ncm: $pick('ncm'),
            cfop: $pick('cfop'),
            csosn: $pick('csosn'),
            originCode: $pick('origin_code'),
            commercialUnit: $pick('commercial_unit'),
            cest: $pick('cest'),
            gtin: filled($product->gtin) ? (string) $product->gtin : null,
            icmsRate: $pick('icms_rate'),
            pisRate: $pick('pis_rate'),
            cofinsRate: $pick('cofins_rate'),
        );
    }
}

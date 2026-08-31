<?php

namespace App\Filament\Tenant\Support;

use App\Models\Category;

/**
 * Opções de `Select` de categoria agrupadas pela categoria pai. Subcategorias
 * de pais diferentes podem ter o mesmo nome — o cabeçalho do grupo (nome do
 * pai) é o que as distingue. Categoria raiz sem subcategoria fica solta no
 * topo, sem grupo.
 *
 * Reusado pelo form de produto e pelo bulk action "Replicar para outra
 * categoria" da listagem de produtos.
 */
final class CategoryOptions
{
    /**
     * @return array<int|string, string|array<int, string>>
     */
    public static function grouped(): array
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->orderBy('display_order')])
            ->orderBy('display_order')
            ->get();

        $options = [];

        foreach ($roots as $root) {
            if ($root->children->isEmpty()) {
                $options[$root->id] = $root->name;

                continue;
            }

            $group = [$root->id => $root->name];

            foreach ($root->children as $child) {
                $group[$child->id] = $child->name;
            }

            $options[$root->name] = $group;
        }

        return $options;
    }
}

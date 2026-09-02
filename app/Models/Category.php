<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'description',
        'display_order',
        'show_in_menu',
        'show_description_in_menu',
        'allows_flavors',
        'inherit_flavor_options',
    ];

    protected function casts(): array
    {
        return [
            'show_in_menu' => 'boolean',
            'show_description_in_menu' => 'boolean',
            'allows_flavors' => 'boolean',
            'inherit_flavor_options' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function flavorQuantityOptions(): HasMany
    {
        return $this->hasMany(FlavorQuantityOption::class)->orderBy('display_order');
    }

    /**
     * Subcategoria que optou por usar as quantidades de sabores da categoria
     * pai em vez de cadastrar as próprias. Categoria raiz sempre retorna false.
     */
    public function inheritsFlavorOptions(): bool
    {
        return $this->parent_id !== null && $this->inherit_flavor_options;
    }

    /**
     * Opções de quantidade de sabores efetivas: as da categoria pai quando a
     * subcategoria está herdando, senão as próprias. Fonte única — todo lugar
     * que decide combo no cardápio/checkout/PDV deve ler por aqui, nunca
     * `flavorQuantityOptions` direto.
     *
     * @return EloquentCollection<int, FlavorQuantityOption>
     */
    public function resolvedFlavorQuantityOptions(): EloquentCollection
    {
        if ($this->inheritsFlavorOptions() && $this->parent !== null) {
            return $this->parent->flavorQuantityOptions;
        }

        return $this->flavorQuantityOptions;
    }

    public function productionLines(): BelongsToMany
    {
        return $this->belongsToMany(ProductionLine::class, 'category_production_line');
    }

    /**
     * Miniatura pra navegação rápida por categoria (melhor reconhecimento
     * visual que só texto): 1ª imagem entre os produtos diretos; senão, 1ª
     * imagem entre os produtos das subcategorias; senão null. Exige as
     * relações `products` e `children.products` carregadas.
     */
    public function navigationThumbnailUrl(): ?string
    {
        $direct = $this->products->first(fn (Product $product) => filled($product->image_url));

        if ($direct) {
            return $direct->image_url;
        }

        return $this->children
            ->flatMap(fn (Category $child) => $child->products)
            ->first(fn (Product $product) => filled($product->image_url))?->image_url;
    }
}

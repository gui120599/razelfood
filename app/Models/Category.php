<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
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
        'display_order',
        'show_in_menu',
        'allows_flavors',
    ];

    protected function casts(): array
    {
        return [
            'show_in_menu' => 'boolean',
            'allows_flavors' => 'boolean',
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

    public function productionLines(): BelongsToMany
    {
        return $this->belongsToMany(ProductionLine::class, 'category_production_line');
    }
}

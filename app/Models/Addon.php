<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Addon extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'price',
        'controls_stock',
        'stock_quantity',
        'show_when_out_of_stock',
        'sales_count',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'controls_stock' => 'boolean',
            'stock_quantity' => 'decimal:2',
            'show_when_out_of_stock' => 'boolean',
            'sales_count' => 'decimal:2',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_addon')
            ->using(ProductAddon::class)
            ->withPivot(['price', 'max_quantity']);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'name',
        'description',
        'image_path',
        'price',
        'promotional_price',
        'promo_starts_at',
        'promo_ends_at',
        'is_visible',
        'controls_stock',
        'stock_quantity',
        'show_when_out_of_stock',
        'bestseller_eligible',
        'sales_count',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'promotional_price' => 'decimal:2',
            'promo_starts_at' => 'datetime',
            'promo_ends_at' => 'datetime',
            'is_visible' => 'boolean',
            'controls_stock' => 'boolean',
            'stock_quantity' => 'decimal:2',
            'show_when_out_of_stock' => 'boolean',
            'bestseller_eligible' => 'boolean',
            'sales_count' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function flashPromotions(): BelongsToMany
    {
        return $this->belongsToMany(FlashPromotion::class, 'flash_promotion_products')
            ->using(FlashPromotionProduct::class)
            ->withPivot(['promotional_price', 'total_quantity', 'sold_quantity']);
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'product_addon')
            ->using(ProductAddon::class)
            ->withPivot(['price', 'max_quantity']);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        );
    }
}

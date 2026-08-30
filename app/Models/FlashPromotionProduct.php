<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class FlashPromotionProduct extends Pivot
{
    use BelongsToTenant;

    protected $table = 'flash_promotion_products';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'flash_promotion_id',
        'product_id',
        'promotional_price',
        'total_quantity',
        'sold_quantity',
    ];

    protected function casts(): array
    {
        return [
            'promotional_price' => 'decimal:2',
            'sold_quantity' => 'decimal:2',
        ];
    }

    public function flashPromotion(): BelongsTo
    {
        return $this->belongsTo(FlashPromotion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

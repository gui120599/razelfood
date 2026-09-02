<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'flash_promotion_id',
        'quantity',
        'unit_price',
        'original_unit_price',
        'note',
        'flavors',
        'addons',
        'addons_total',
        'gifts',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'original_unit_price' => 'decimal:2',
            'flavors' => 'array',
            'addons' => 'array',
            'addons_total' => 'decimal:2',
            'gifts' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function flashPromotion(): BelongsTo
    {
        return $this->belongsTo(FlashPromotion::class);
    }
}

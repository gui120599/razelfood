<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductAddon extends Pivot
{
    use BelongsToTenant;

    protected $table = 'product_addon';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'addon_id',
        'price',
        'max_quantity',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}

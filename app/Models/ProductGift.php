<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Vínculo entre um produto principal e um produto do catálogo oferecido como
 * brinde grátis (RN-53). Espelha ProductAddon: pivot com `id()`, sem timestamps,
 * `tenant_id` preenchido pelo hook de BelongsToTenant. `quantity` = unidades do
 * brinde por unidade do produto principal; `is_active` liga/desliga a oferta sem
 * apagar o cadastro; `flavor_counts` restringe a quais quantidades de sabores o
 * brinde é oferecido (null = todas).
 */
class ProductGift extends Pivot
{
    use BelongsToTenant;

    protected $table = 'product_gift';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'gift_product_id',
        'quantity',
        'is_active',
        'flavor_counts',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'flavor_counts' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function giftProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }
}

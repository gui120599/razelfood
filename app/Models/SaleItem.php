<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item AVULSO de uma venda (produto + quantidade lançados direto na conta,
 * sem pedido). Nome e preço são snapshot do momento da venda.
 */
class SaleItem extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'total',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}

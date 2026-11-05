<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot de um pedido cobrado por uma venda (sobrevive ao cancelamento da
 * venda, quando `orders.sale_id` volta a nulo).
 */
class SaleOrder extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'order_id',
        'order_label',
        'order_status',
        'client_name',
        'order_total',
    ];

    protected function casts(): array
    {
        return [
            'order_status' => OrderStatus::class,
            'order_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

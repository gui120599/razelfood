<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desconto em reais dado a um item de pedido DENTRO de uma venda (campo "Desc. R$" da Nova Venda).
 * Vive e morre com a reserva do item na venda; o item do pedido em si não muda.
 */
class SaleOrderItemDiscount extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'order_item_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}

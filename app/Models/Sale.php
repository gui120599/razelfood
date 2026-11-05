<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venda / fechamento de conta (Fase 1.0). Agrega pedidos inteiros
 * (`orders.sale_id` / `order_items.sale_id`), itens avulsos (`sale_items`) e os
 * pagamentos recebidos (`sale_payments`). Criada e cancelada só por
 * App\Actions\Sales\FinalizeSale / CancelSale.
 */
class Sale extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'number',
        'status',
        'items_total',
        'discount_total',
        'delivery_fee',
        'total',
        'finalized_at',
        'finalized_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_note',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SaleStatus::class,
            'items_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Pedidos cobrados na época da venda (snapshot; persiste após o cancelamento).
     */
    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function displayNumber(): string
    {
        return '#'.$this->number;
    }
}

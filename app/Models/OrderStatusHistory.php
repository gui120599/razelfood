<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro append-only de cada transição de status de um pedido (quem, de
 * onde, para onde, quando). Fonte para a timeline do modal de detalhes da
 * Central de Pedidos e para futuros indicadores (RF-31) — não substitui os
 * timestamps *_at de Order, que continuam sendo a fonte usada onde já eram
 * consumidos (OrderInfolist, OrderStatusTimeline público).
 */
class OrderStatusHistory extends TenantScopedModel
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'status_from',
        'status_to',
        'user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status_from' => OrderStatus::class,
            'status_to' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

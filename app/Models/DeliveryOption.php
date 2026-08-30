<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOption extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'requires_address',
        'show_in_menu',
        'delivery_fee',
        'min_order_for_free_delivery',
    ];

    protected function casts(): array
    {
        return [
            'requires_address' => 'boolean',
            'show_in_menu' => 'boolean',
            'delivery_fee' => 'decimal:2',
            'min_order_for_free_delivery' => 'decimal:2',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}

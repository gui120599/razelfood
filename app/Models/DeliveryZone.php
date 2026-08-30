<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryZone extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'delivery_fee',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
        ];
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(DeliveryZoneNeighborhood::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use App\Support\NeighborhoodNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryZoneNeighborhood extends TenantScopedModel
{
    protected $fillable = [
        'tenant_id',
        'delivery_zone_id',
        'neighborhood',
        'city',
    ];

    protected function neighborhood(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => NeighborhoodNormalizer::normalize($value),
        );
    }

    protected function city(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => NeighborhoodNormalizer::normalize($value),
        );
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }
}

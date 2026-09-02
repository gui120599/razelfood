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
        'city_id',
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

    /**
     * Cidade do catálogo global (App\Models\City). Nomeada `cityRecord` porque
     * `city` já é o atributo string normalizado. Pode ser null em registros
     * antigos que o backfill não conseguiu casar.
     */
    public function cityRecord(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}

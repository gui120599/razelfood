<?php

namespace App\Models;

use App\Support\NeighborhoodNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global de cidades (não tenant-scoped), alimentado pela
 * sincronização de localidades (App\Services\Address\LocationSyncService).
 * Ao contrário de App\Models\DeliveryZoneNeighborhood, aqui `name` mantém a
 * grafia original — só `normalized_name` é normalizado, como chave auxiliar
 * de busca/deduplicação (ver App\Support\NeighborhoodNormalizer).
 */
class City extends Model
{
    protected $fillable = [
        'state_id',
        'name',
        'ibge_code',
    ];

    protected function casts(): array
    {
        return [
            'ibge_code' => 'integer',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'name' => $value,
                'normalized_name' => NeighborhoodNormalizer::normalize($value),
            ],
        );
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    public function locationSyncs(): HasMany
    {
        return $this->hasMany(LocationSync::class);
    }
}

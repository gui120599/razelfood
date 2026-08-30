<?php

namespace App\Models;

use App\Support\NeighborhoodNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catálogo global de bairros (não tenant-scoped), alimentado pela
 * sincronização de localidades (App\Services\Address\LocationSyncService).
 * `name` mantém a grafia original vinda do ViaCEP; `normalized_name` é a
 * chave auxiliar usada pra deduplicação (ver App\Support\NeighborhoodNormalizer).
 */
class Neighborhood extends Model
{
    protected $fillable = [
        'city_id',
        'name',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => [
                'name' => $value,
                'normalized_name' => NeighborhoodNormalizer::normalize($value),
            ],
        );
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}

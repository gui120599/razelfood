<?php

namespace App\Models;

use App\Enums\LocationSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um "run" de sincronização de bairros (UF+Cidade+faixa de CEP), com
 * checkpoint persistente (`current_cep`/`last_confirmed_cep`) para permitir
 * pausar/retomar sem reprocessar do zero. Ver App\Jobs\ProcessLocationSyncChunkJob
 * e App\Services\Address\LocationSyncService.
 */
class LocationSync extends Model
{
    protected $fillable = [
        'state_id',
        'city_id',
        'cep_start',
        'cep_end',
        'current_cep',
        'last_confirmed_cep',
        'total_ceps',
        'ceps_processed',
        'ceps_valid',
        'ceps_invalid',
        'neighborhoods_found',
        'neighborhoods_created',
        'errors_count',
        'status',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => LocationSyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LocationSyncLog::class);
    }

    /**
     * Heurística de UX (não é fonte de verdade): sinaliza um run que diz
     * estar "processando" mas não teve nenhuma atualização de checkpoint
     * recentemente — provavelmente o worker morreu no meio da cadeia de
     * Jobs. Usado só pra oferecer a ação "Retomar" antes do normal.
     */
    public function isStuck(): bool
    {
        return $this->status === LocationSyncStatus::Processing
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes(5));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Não estende TenantScopedModel de propósito: overrides são geridos só pelo
 * painel central da Razel Tec (RN-44), nunca dentro do contexto de
 * requisição do próprio tenant, então não deve carregar o TenantScope.
 */
class TenantFeatureOverride extends Model
{
    protected $fillable = [
        'tenant_id',
        'feature_id',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}

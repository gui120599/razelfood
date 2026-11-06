<?php

namespace App\Models;

use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de auditoria de um webhook da NFE.io. Append-only: gravado antes de
 * decidir se a chamada é autêntica e nunca editado depois (só o resultado).
 */
class FiscalWebhook extends TenantScopedModel
{
    use MassPrunable;

    /** Dias de retenção da auditoria. */
    public const RETENTION_DAYS = 90;

    public const OUTCOME_APPLIED = 'applied';

    public const OUTCOME_UNCHANGED = 'unchanged';

    public const OUTCOME_UNKNOWN_DOCUMENT = 'unknown_document';

    public const OUTCOME_FEATURE_OFF = 'feature_off';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'event',
        'provider_id',
        'fiscal_document_id',
        'payload',
        'signature_valid',
        'outcome',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Consulta de `model:prune` (agendado): remove registros com mais de RETENTION_DAYS dias.
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('received_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}

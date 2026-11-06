<?php

namespace App\Models;

use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * NFC-e de uma venda. Criado/atualizado só pelas Actions de App\Actions\Fiscal.
 */
class FiscalDocument extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'sequence_id',
        'environment',
        'model',
        'series',
        'number',
        'status',
        'provider_status',
        'provider_id',
        'decision',
        'user_id',
        'sent_at',
        'authorized_at',
        'cancel_requested_at',
        'cancel_requested_by_user_id',
        'cancelled_at',
        'error_message',
        'buyer_omission_reason',
    ];

    protected function casts(): array
    {
        return [
            'environment' => FiscalEnvironment::class,
            'model' => 'integer',
            'series' => 'integer',
            'number' => 'integer',
            'status' => FiscalDocumentStatus::class,
            'decision' => FiscalDocumentDecision::class,
            'sent_at' => 'datetime',
            'authorized_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Minutos sem resposta para considerar abandonado um envio que não gravou o identificador. */
    public const STALE_SENDING_MINUTES = 10;

    /** Janela em que a tela da venda consulta sozinha a NFE.io. */
    public const POLL_WINDOW_MINUTES = 15;

    /**
     * Envio que ficou "em processamento" sem identificador da NFE.io por tempo demais
     * (processo caiu entre o POST e a gravação): deixa de bloquear a venda.
     */
    public function isStaleSending(): bool
    {
        return $this->status === FiscalDocumentStatus::Processing
            && blank($this->provider_id)
            && $this->sent_at !== null
            && $this->sent_at->lt(now()->subMinutes(self::STALE_SENDING_MINUTES));
    }

    /**
     * Vivo = impede cancelar a venda e emitir outra nota (RN-FIS-16).
     */
    public function isLive(): bool
    {
        return $this->status->isAlive() && ! $this->isStaleSending();
    }

    /**
     * O envio pode ter chegado à NFE.io sem que saibamos (timeout, 5xx, resposta sem
     * identificador): reenviar pode duplicar a nota, então a tela avisa antes.
     */
    public function isAmbiguousSend(): bool
    {
        return in_array($this->status, [FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Processing], true)
            && blank($this->provider_id)
            && $this->sent_at !== null;
    }

    public function shouldPoll(): bool
    {
        if (blank($this->provider_id)) {
            return false;
        }

        $since = match ($this->status) {
            FiscalDocumentStatus::Processing => $this->sent_at,
            FiscalDocumentStatus::CancelRequested => $this->cancel_requested_at,
            default => null,
        };

        return $since !== null && $since->gte(now()->subMinutes(self::POLL_WINDOW_MINUTES));
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', FiscalDocumentStatus::aliveValues())
            ->where(fn (Builder $where): Builder => $where
                ->where('status', '!=', FiscalDocumentStatus::Processing->value)
                ->orWhereNotNull('provider_id')
                ->orWhereNull('sent_at')
                ->orWhere('sent_at', '>=', now()->subMinutes(self::STALE_SENDING_MINUTES)));
    }

    /** Horas sem uso para um número reservado virar candidato à inutilização. */
    public const UNUSED_NUMBER_AFTER_HOURS = 24;

    /**
     * Números reservados e nunca consumidos na SEFAZ (candidatos à inutilização): estados que não
     * consomem número, há mais de 24 h. `Processing` sem identificador é ambíguo e fica de fora.
     */
    public function scopeUnusedNumbers(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [FiscalDocumentStatus::Reserved->value, FiscalDocumentStatus::FailedToSend->value, FiscalDocumentStatus::Failed->value])
            ->where('created_at', '<', now()->subHours(self::UNUSED_NUMBER_AFTER_HOURS));
    }

    public function items(): HasMany
    {
        return $this->hasMany(FiscalDocumentItem::class)->orderBy('position');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(FiscalWebhook::class)->latest('received_at');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(FiscalNumberSequence::class, 'sequence_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

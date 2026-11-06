<?php

namespace App\Actions\Fiscal;

use App\Enums\FiscalDocumentStatus;
use App\Jobs\CompleteSaleCancellationJob;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único ponto que traduz o status da NFE.io (webhook, "Atualizar status", polling e
 * reconciliação) para o estado do documento. Relê a linha SOB LOCK antes de decidir (o model
 * recebido pode estar velho: o webhook pode ter gravado algo enquanto a consulta HTTP rodava),
 * é idempotente e não rebaixa: nota emitida/cancelada nunca volta para "processando" por um
 * aviso atrasado, e "cancelamento solicitado" só sai por um resultado de cancelamento.
 *
 * Depois de aplicar, cuida do cancelamento pendente da venda (ação combinada): nota cancelada
 * ⇒ conclui o cancelamento da venda; cancelamento que falhou ⇒ solta o pendente.
 */
class ApplyFiscalProviderStatus
{
    /** Passado esse tempo com a NFE.io ainda informando "Issued", o cancelamento é dado como não efetivado. */
    public const CANCEL_UNCONFIRMED_MINUTES = 30;

    /**
     * Estados que cada estado atual aceita; ausência da chave = aceita qualquer um.
     *
     * @var array<string, list<FiscalDocumentStatus>>
     */
    private const ALLOWED = [
        'cancelled' => [],
        'issued' => [FiscalDocumentStatus::Issued, FiscalDocumentStatus::Cancelled, FiscalDocumentStatus::CancelFailed],
        'cancel_requested' => [FiscalDocumentStatus::CancelRequested, FiscalDocumentStatus::Cancelled, FiscalDocumentStatus::CancelFailed],
        'cancel_failed' => [FiscalDocumentStatus::CancelFailed, FiscalDocumentStatus::Cancelled, FiscalDocumentStatus::Issued],
        'failed' => [FiscalDocumentStatus::Failed, FiscalDocumentStatus::Issued, FiscalDocumentStatus::Cancelled],
    ];

    /**
     * Estado que o documento assumiria com este status do provedor (null = ignorar). Usado pelo
     * comando em `--simular` para mostrar exatamente o que a execução real faria.
     */
    public function resolve(FiscalDocument $document, ?string $providerStatus): ?FiscalDocumentStatus
    {
        if (blank($providerStatus)) {
            return null;
        }

        $new = FiscalDocumentStatus::fromProvider($providerStatus);

        if ($this->isUnconfirmedCancellation($document, $new)) {
            return FiscalDocumentStatus::Issued;
        }

        $allowed = self::ALLOWED[$document->status->value] ?? null;

        return $allowed !== null && ! in_array($new, $allowed, true) ? null : $new;
    }

    /**
     * @param  array<string, mixed>  $body  Corpo da NFE.io (consulta ou webhook), só para o motivo.
     * @return bool Houve mudança de estado ou de status cru?
     */
    public function __invoke(FiscalDocument $document, ?string $providerStatus, array $body = []): bool
    {
        if (blank($providerStatus)) {
            return false;
        }

        $outcome = DB::transaction(function () use ($document, $providerStatus, $body): ?array {
            $locked = FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
                ->whereKey($document->id)->lockForUpdate()->first();

            $new = $locked === null ? null : $this->resolve($locked, $providerStatus);

            if ($new === null || ($locked->status === $new && $locked->provider_status === $providerStatus)) {
                return null;
            }

            $previous = $locked->status;
            $unconfirmed = $this->isUnconfirmedCancellation($locked, $new);
            $attributes = ['provider_status' => $providerStatus, 'status' => $new];

            if ($new === FiscalDocumentStatus::Issued) {
                $attributes['authorized_at'] = $locked->authorized_at ?? now();
                $attributes['error_message'] = $unconfirmed
                    ? 'O cancelamento da NFC-e não foi confirmado pela NFE.io: a nota continua autorizada.'
                    : null;
            }

            if ($new === FiscalDocumentStatus::Cancelled) {
                $attributes['cancelled_at'] = $locked->cancelled_at ?? now();
            }

            if ($new === FiscalDocumentStatus::Failed) {
                $attributes['error_message'] = $this->reason($body) ?? 'A NFC-e foi rejeitada pela NFE.io.';
            }

            if ($new === FiscalDocumentStatus::CancelFailed) {
                $attributes['error_message'] = $this->reason($body) ?? 'A NFE.io não conseguiu cancelar a NFC-e (por exemplo, fora do prazo). Confira no painel da NFE.io.';
            }

            $locked->update($attributes);

            return ['previous' => $previous, 'new' => $new, 'tenant_id' => $locked->tenant_id, 'sale_id' => $locked->sale_id];
        }, attempts: 3);

        $document->refresh();

        if ($outcome === null) {
            return false;
        }

        if ($outcome['previous'] !== $outcome['new']) {
            $this->settlePendingSaleCancellation($outcome['tenant_id'], $outcome['sale_id'], $outcome['previous'], $outcome['new']);
        }

        return true;
    }

    private function isUnconfirmedCancellation(FiscalDocument $document, FiscalDocumentStatus $new): bool
    {
        return $document->status === FiscalDocumentStatus::CancelRequested
            && $new === FiscalDocumentStatus::Issued
            && $document->cancel_requested_at !== null
            && $document->cancel_requested_at->lt(now()->subMinutes(self::CANCEL_UNCONFIRMED_MINUTES));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body): ?string
    {
        $reason = $body['statusReason'] ?? $body['message'] ?? null;

        return is_string($reason) && $reason !== '' ? Str::limit($reason, 500) : null;
    }

    private function settlePendingSaleCancellation(int $tenantId, int $saleId, FiscalDocumentStatus $previous, FiscalDocumentStatus $new): void
    {
        $sale = Sale::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->find($saleId);

        if ($sale === null || ! $sale->hasPendingCancellation()) {
            return;
        }

        if ($new === FiscalDocumentStatus::Cancelled) {
            // Na fila (tem tries/backoff e precisa sobreviver a falha transitória); o `queue:work`
            // agendado a cada minuto a consome. `dispatchAfterResponse` perderia as retentativas.
            CompleteSaleCancellationJob::dispatch($tenantId, $sale->id)->afterCommit();

            return;
        }

        $cancellationFailed = $new === FiscalDocumentStatus::CancelFailed
            || ($previous === FiscalDocumentStatus::CancelRequested && $new === FiscalDocumentStatus::Issued);

        if ($cancellationFailed) {
            // O cancelamento da nota não se efetivou: a venda continua como estava e o pendente é solto.
            $sale->update(['pending_cancellation_note' => null, 'pending_cancellation_by_user_id' => null, 'pending_cancellation_at' => null]);
        }
    }
}

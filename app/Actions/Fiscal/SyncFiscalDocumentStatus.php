<?php

namespace App\Actions\Fiscal;

use App\Enums\FiscalDocumentStatus;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\NfeIoException;
use App\Models\FiscalDocument;
use App\Models\Scopes\TenantScope;
use App\Models\TenantFiscalSettings;
use App\Services\Fiscal\NfeIoClient;
use Illuminate\Support\Str;

/**
 * Consulta a NFE.io e espelha o estado da nota no documento (botão "Atualizar
 * status"; a reconciliação agendada e o webhook chegam na Fase 1.3).
 */
class SyncFiscalDocumentStatus
{
    public function __construct(private readonly NfeIoClient $client) {}

    /**
     * @throws FiscalDocumentException
     */
    public function __invoke(FiscalDocument $document): FiscalDocument
    {
        if (blank($document->provider_id)) {
            throw new FiscalDocumentException('Esta NFC-e ainda não foi enviada à NFE.io: não há status para consultar.');
        }

        $settings = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $document->tenant_id)->first()
            ?? throw new FiscalDocumentException('Os dados fiscais do estabelecimento não foram encontrados.');

        try {
            $body = $this->client->find($settings, $document->provider_id);
        } catch (NfeIoException $exception) {
            throw new FiscalDocumentException($exception->getMessage(), previous: $exception);
        }

        $providerStatus = is_string($body['status'] ?? null) ? $body['status'] : null;
        $status = FiscalDocumentStatus::fromProvider($providerStatus);

        // Uma consulta atrasada nunca rebaixa uma nota já emitida/cancelada para "processando".
        if ($status === FiscalDocumentStatus::Processing && in_array($document->status, [FiscalDocumentStatus::Issued, FiscalDocumentStatus::Cancelled], true)) {
            return $document;
        }

        $attributes = ['provider_status' => $providerStatus, 'status' => $status];

        if ($status === FiscalDocumentStatus::Issued) {
            $attributes['authorized_at'] = $document->authorized_at ?? now();
            $attributes['error_message'] = null;
        }

        if ($status === FiscalDocumentStatus::Cancelled) {
            $attributes['cancelled_at'] = $document->cancelled_at ?? now();
        }

        if ($status === FiscalDocumentStatus::Failed) {
            $reason = $body['statusReason'] ?? $body['message'] ?? null;
            $attributes['error_message'] = is_string($reason) && $reason !== ''
                ? Str::limit($reason, 500)
                : 'A NFC-e foi rejeitada pela NFE.io.';
        }

        $document->update($attributes);

        return $document->refresh();
    }
}

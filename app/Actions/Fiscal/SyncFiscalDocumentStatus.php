<?php

namespace App\Actions\Fiscal;

use App\Exceptions\FiscalDocumentException;
use App\Exceptions\NfeIoException;
use App\Models\FiscalDocument;
use App\Models\Scopes\TenantScope;
use App\Models\TenantFiscalSettings;
use App\Services\Fiscal\NfeIoClient;

/**
 * Consulta a NFE.io e espelha o estado da nota no documento ("Atualizar status",
 * polling da tela da venda e reconciliação). A tradução do status é de
 * ApplyFiscalProviderStatus (a mesma usada pelo webhook).
 */
class SyncFiscalDocumentStatus
{
    public function __construct(
        private readonly NfeIoClient $client,
        private readonly ApplyFiscalProviderStatus $apply,
    ) {}

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

        ($this->apply)($document, is_string($body['status'] ?? null) ? $body['status'] : null, $body);

        return $document->refresh();
    }
}

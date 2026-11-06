<?php

namespace App\Actions\Fiscal;

use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Enums\SaleStatus;
use App\Enums\TaxRegime;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\NfeIoException;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use App\Services\Fiscal\NfeIoClient;
use App\Support\FeatureKey;
use App\Support\Fiscal\FiscalReadiness;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emite a NFC-e de uma venda finalizada (manual ou automática).
 *
 * Revalida TUDO no momento da emissão (não confia só em `enabled_at`): feature,
 * emissão habilitada, regime suportado, prontidão do tenant + dos produtos/formas DA
 * VENDA, produção só depois de uma nota de homologação emitida (R-NF-01) e ausência de
 * nota viva (RN-FIS-16). Um lock de cache por venda impede dois cliques/jobs ao mesmo
 * tempo (RN-FIS-13). O HTTP roda FORA de transação: a reserva do número é uma transação
 * curta (ReserveFiscalNumber) e o resultado é gravado depois. Falha de envio mantém o
 * número reservado (FailedToSend) para o reenvio reaproveitá-lo.
 */
class IssueFiscalDocument
{
    public function __construct(
        private readonly ReserveFiscalNumber $reserveFiscalNumber,
        private readonly BuildNfcePayload $buildNfcePayload,
        private readonly FiscalReadiness $readiness,
        private readonly NfeIoClient $client,
    ) {}

    /**
     * @throws FiscalDocumentException
     */
    public function __invoke(Sale $sale, FiscalDocumentDecision $decision, ?User $user = null): FiscalDocument
    {
        $tenant = Tenant::query()->with(['plan.features', 'featureOverrides.feature'])->find($sale->tenant_id)
            ?? throw new FiscalDocumentException('Estabelecimento não encontrado.');

        $sale = Sale::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($sale->id);
        $settings = $this->assertCanIssue($tenant, $sale);

        // O lock tem de durar mais que o timeout do HTTP, senão expira com o POST em andamento.
        $lock = Cache::lock("tenant:{$tenant->id}:fiscal:sale:{$sale->id}", max(120, (int) config('services.nfeio.timeout') + 60));

        if (! $lock->get()) {
            throw new FiscalDocumentException('Já existe uma emissão em andamento para esta venda. Aguarde alguns instantes.');
        }

        try {
            return $this->issue($tenant, $sale, $settings, $decision, $user);
        } finally {
            $lock->release();
        }
    }

    private function issue(Tenant $tenant, Sale $sale, TenantFiscalSettings $settings, FiscalDocumentDecision $decision, ?User $user): FiscalDocument
    {
        $document = ($this->reserveFiscalNumber)($sale, $tenant, $settings, $decision, $user);

        try {
            $payload = ($this->buildNfcePayload)($document, $sale, $settings);
        } catch (FiscalDocumentException $exception) {
            $document->update(['status' => FiscalDocumentStatus::Reserved, 'error_message' => Str::limit($exception->getMessage(), 500)]);

            throw $exception;
        }

        $this->markSending($document, $sale, $payload->buyerOmissionReason);

        try {
            $body = $this->client->issue($settings, $payload->body);
        } catch (NfeIoException $exception) {
            // Recusa definitiva (4xx): a NFE.io não aceitou, reenviar é seguro. Timeout, queda de
            // conexão e 5xx são ambíguos: a nota pode ter sido recebida (sent_at é mantido para a
            // tela avisar antes do reenvio).
            $definitive = $exception->httpStatus !== null && $exception->httpStatus < 500;

            $document->update([
                'status' => FiscalDocumentStatus::FailedToSend,
                'error_message' => Str::limit($exception->getMessage(), 500),
                'sent_at' => $definitive ? null : $document->sent_at,
            ]);

            return $document->refresh();
        }

        $providerStatus = is_string($body['status'] ?? null) ? $body['status'] : null;
        $providerId = is_string($body['id'] ?? null) && $body['id'] !== '' ? $body['id'] : null;

        if ($providerId === null) {
            $document->update([
                'status' => FiscalDocumentStatus::FailedToSend,
                'provider_status' => $providerStatus,
                'error_message' => 'A NFE.io aceitou o envio mas não devolveu o identificador da nota. Não reenvie sem conferir na NFE.io.',
            ]);

            return $document->refresh();
        }

        $status = FiscalDocumentStatus::fromProvider($providerStatus);

        $document->update([
            'provider_id' => $providerId,
            'provider_status' => $providerStatus,
            'status' => $status,
            'authorized_at' => $status === FiscalDocumentStatus::Issued ? now() : null,
        ]);

        return $document->refresh();
    }

    /**
     * Torna o documento "vivo" (Processing) ANTES do HTTP, sob o lock da venda: a partir daqui
     * CancelSale enxerga a nota e recusa o cancelamento; e se a venda foi cancelada entre a
     * reserva e este ponto, nada é enviado.
     *
     * @throws FiscalDocumentException
     */
    private function markSending(FiscalDocument $document, Sale $sale, ?string $buyerOmissionReason): void
    {
        try {
            DB::transaction(function () use ($document, $sale, $buyerOmissionReason): void {
                $locked = Sale::query()->withoutGlobalScope(TenantScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== SaleStatus::Finalized) {
                    throw new FiscalDocumentException('A venda foi cancelada: a NFC-e não foi enviada.');
                }

                $document->update([
                    'status' => FiscalDocumentStatus::Processing,
                    'sent_at' => now(),
                    'error_message' => null,
                    'buyer_omission_reason' => $buyerOmissionReason,
                ]);
            }, attempts: 3);
        } catch (FiscalDocumentException $exception) {
            $document->update(['status' => FiscalDocumentStatus::Reserved, 'error_message' => Str::limit($exception->getMessage(), 500)]);

            throw $exception;
        }
    }

    /**
     * @throws FiscalDocumentException
     */
    private function assertCanIssue(Tenant $tenant, Sale $sale): TenantFiscalSettings
    {
        if (! $tenant->hasFeature(FeatureKey::NFE_EMISSAO)) {
            throw new FiscalDocumentException('A emissão de NFC-e não está disponível no plano deste estabelecimento.');
        }

        $settings = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->first();

        if ($settings === null || ! $settings->isEnabled()) {
            throw new FiscalDocumentException('A emissão de NFC-e não está habilitada. Ative-a em Configurações > Dados fiscais.');
        }

        if (! $settings->tax_regime->isSupported()) {
            throw new FiscalDocumentException(TaxRegime::unsupportedMessage());
        }

        if ($sale->status !== SaleStatus::Finalized) {
            throw new FiscalDocumentException('Só é possível emitir NFC-e de uma venda finalizada.');
        }

        $report = $this->readiness->forSale($sale);

        if (! $report->ready()) {
            $first = $report->issues[0];

            throw new FiscalDocumentException('Há pendências para emitir a NFC-e: '.$first->message.($first->items !== [] ? ' ('.implode('; ', $first->items).')' : ''));
        }

        if ($settings->environment === FiscalEnvironment::Production && ! $this->hasIssuedTestDocument($tenant)) {
            throw new FiscalDocumentException('Antes de emitir em produção, emita ao menos uma NFC-e de teste (homologação) com sucesso.');
        }

        if ($sale->hasLiveFiscalDocument()) {
            throw new FiscalDocumentException('Esta venda já tem uma NFC-e emitida ou em processamento.');
        }

        return $settings;
    }

    private function hasIssuedTestDocument(Tenant $tenant): bool
    {
        return FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('environment', FiscalEnvironment::Test->value)
            ->where('status', FiscalDocumentStatus::Issued->value)
            ->exists();
    }
}

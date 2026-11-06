<?php

namespace App\Actions\Fiscal;

use App\Enums\FiscalDocumentStatus;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\NfeIoException;
use App\Models\FiscalDocument;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use App\Services\Fiscal\NfeIoClient;
use App\Support\FeatureKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pede o cancelamento de uma NFC-e AUTORIZADA (RN-FIS-17). Só `Issued` cancela; o pedido
 * vira `CancelRequested` e a confirmação chega pelo webhook ou pela reconciliação (que
 * preenche `cancelled_at`). Uma recusa imediata (por exemplo, fora do prazo da SEFAZ) volta
 * como mensagem ao operador e deixa o estado exatamente como estava. HTTP fora de transação,
 * com o mesmo lock por venda da emissão.
 */
class CancelFiscalDocument
{
    public function __construct(private readonly NfeIoClient $client) {}

    /**
     * @throws FiscalDocumentException
     */
    public function __invoke(FiscalDocument $document, ?User $user = null): FiscalDocument
    {
        $tenant = Tenant::query()->with(['plan.features', 'featureOverrides.feature'])->find($document->tenant_id)
            ?? throw new FiscalDocumentException('Estabelecimento não encontrado.');

        if (! $tenant->hasFeature(FeatureKey::NFE_EMISSAO)) {
            throw new FiscalDocumentException('A emissão de NFC-e não está disponível no plano deste estabelecimento.');
        }

        $settings = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->first();

        if ($settings === null || ! $settings->hasCredentials()) {
            throw new FiscalDocumentException('As credenciais da NFE.io não estão preenchidas.');
        }

        $lock = Cache::lock("tenant:{$tenant->id}:fiscal:sale:{$document->sale_id}", max(120, (int) config('services.nfeio.timeout') + 60));

        if (! $lock->get()) {
            throw new FiscalDocumentException('Já existe uma operação fiscal em andamento para esta venda. Aguarde alguns instantes.', requestMayHaveBeenSent: true);
        }

        try {
            // Relê sob o lock: o documento recebido pode estar defasado (webhook, outro operador).
            $fresh = FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)->findOrFail($document->id);

            if ($fresh->status !== FiscalDocumentStatus::Issued || blank($fresh->provider_id)) {
                // Outro operador, o webhook ou a reconciliação já mexeu na nota: o estado mudou.
                throw new FiscalDocumentException('Só é possível cancelar uma NFC-e autorizada.', requestMayHaveBeenSent: $fresh->status === FiscalDocumentStatus::CancelRequested || $fresh->status === FiscalDocumentStatus::Cancelled);
            }

            try {
                $this->client->cancel($settings, $fresh->provider_id);
            } catch (NfeIoException $exception) {
                $ambiguous = $exception->httpStatus === null || $exception->httpStatus >= 500;

                throw new FiscalDocumentException(
                    $exception->getMessage().($ambiguous ? ' O pedido pode ter chegado à NFE.io: use "Atualizar status" antes de tentar de novo.' : ''),
                    requestMayHaveBeenSent: $ambiguous,
                    previous: $exception,
                );
            }

            // Só marca "cancelamento solicitado" se a nota ainda está autorizada: o webhook pode ter
            // gravado "Cancelled" enquanto o DELETE rodava, e isso nunca pode ser sobrescrito.
            DB::transaction(function () use ($fresh, $user): void {
                $row = FiscalDocument::query()->withoutGlobalScope(TenantScope::class)->whereKey($fresh->id)->lockForUpdate()->first();

                if ($row !== null && $row->status === FiscalDocumentStatus::Issued) {
                    $row->update([
                        'status' => FiscalDocumentStatus::CancelRequested,
                        'cancel_requested_at' => now(),
                        'cancel_requested_by_user_id' => $user?->id,
                        'error_message' => null,
                    ]);
                }
            }, attempts: 3);

            return $fresh->refresh();
        } finally {
            $lock->release();
        }
    }
}

<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Fiscal\ApplyFiscalProviderStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalWebhook;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Support\FeatureKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Webhook da NFE.io por tenant (`POST /webhooks/nfeio/{token}`). O tenant vem do token
 * da URL; a chamada é autenticada com o segredo DELE (HMAC-SHA1 do corpo no header
 * `X-Hub-Signature`, como o Pizzaria-App). O payload é gravado antes de qualquer decisão.
 * O documento é procurado só no escopo do tenant: nota de outro tenant nunca é tocada.
 */
class NfeIoWebhookController extends TenantWebhookController
{
    private const MAX_PAYLOAD_BYTES = 65536;

    private ?FiscalWebhook $record = null;

    public function __construct(private readonly ApplyFiscalProviderStatus $apply) {}

    protected function recordPayload(Request $request, Tenant $tenant): void
    {
        $payload = $this->payload($request);
        $event = $payload['event'] ?? $payload['type'] ?? null;
        $providerId = $payload['id'] ?? null;

        $this->record = FiscalWebhook::create([
            'tenant_id' => $tenant->id,
            'event' => is_string($event) ? Str::limit($event, 250, '') : null,
            'provider_id' => is_string($providerId) || is_int($providerId) ? Str::limit((string) $providerId, 250, '') : null,
            'payload' => $payload,
            'received_at' => now(),
        ]);
    }

    protected function isAuthentic(Request $request, Tenant $tenant): bool
    {
        $secret = $this->secret();
        $received = $request->header('X-Hub-Signature');

        // Sem segredo configurado nunca se aceita sem verificar (diferente do Pizzaria-App).
        $valid = $secret !== null
            && is_string($received)
            && hash_equals(hash_hmac('sha1', $request->getContent(), $secret), $this->normalizeSignature($received));

        $this->record?->update([
            'signature_valid' => $secret === null ? null : $valid,
            'outcome' => $valid ? null : FiscalWebhook::OUTCOME_REJECTED,
            'processed_at' => $valid ? null : now(),
        ]);

        return $valid;
    }

    protected function process(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            return $this->apply($request, $tenant);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish(FiscalWebhook::OUTCOME_ERROR, 500);
        }
    }

    private function apply(Request $request, Tenant $tenant): JsonResponse
    {
        if (! $tenant->hasFeature(FeatureKey::NFE_EMISSAO)) {
            return $this->finish(FiscalWebhook::OUTCOME_FEATURE_OFF);
        }

        $providerId = $this->record?->provider_id;
        $status = $this->record?->payload['status'] ?? null;

        $document = filled($providerId)
            ? FiscalDocument::query()->where('provider_id', $providerId)->first()
            : null;

        if ($document === null || ! is_string($status)) {
            return $this->finish(FiscalWebhook::OUTCOME_UNKNOWN_DOCUMENT);
        }

        $changed = ($this->apply)($document, $status, $this->record->payload);

        return $this->finish($changed ? FiscalWebhook::OUTCOME_APPLIED : FiscalWebhook::OUTCOME_UNCHANGED, 200, $document);
    }

    protected function rejectionStatus(): int
    {
        return 403;
    }

    /**
     * Corpo do webhook para auditoria: o JSON cru (não a query string), com bytes inválidos
     * substituídos e um teto de tamanho — quem tem o token não pode encher a tabela.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $raw = $request->getContent();

        if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return ['_truncated' => true, 'bytes' => strlen($raw)];
        }

        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : $request->post();

        $clean = json_decode((string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), true);

        return is_array($clean) ? $clean : [];
    }

    /**
     * Tolera o prefixo `sha1=` e hex em maiúsculas (o Pizzaria-App comprova só o hex puro).
     */
    private function normalizeSignature(string $received): string
    {
        return strtolower(preg_replace('/^sha1=/i', '', trim($received)) ?? '');
    }

    private function finish(string $outcome, int $status = 200, ?FiscalDocument $document = null): JsonResponse
    {
        $this->record?->update([
            'outcome' => $outcome,
            'fiscal_document_id' => $document?->id,
            'processed_at' => now(),
        ]);

        return response()->json(['message' => $status === 200 ? 'Atualizado com sucesso!' : 'Falha ao processar o webhook.'], $status);
    }

    /**
     * Segredo do webhook DESTE tenant (contexto já definido), ou null se ausente/ilegível.
     */
    private function secret(): ?string
    {
        $settings = TenantFiscalSettings::query()->first();

        if ($settings === null || blank($settings->getRawOriginal('nfeio_webhook_secret'))) {
            return null;
        }

        try {
            $secret = $settings->nfeio_webhook_secret;
        } catch (DecryptException) {
            return null;
        }

        return filled($secret) ? (string) $secret : null;
    }
}

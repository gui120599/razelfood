<?php

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\TenantContextException;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base dos controllers de webhook por tenant (MT-02), atrás do middleware
 * `webhook.tenant:{provedor}`.
 *
 * Fixa a ORDEM do fluxo: primeiro grava o payload bruto, só depois decide se
 * a chamada é autêntica (401/403) e então processa. Assim uma tentativa não
 * autenticada continua registrada para auditoria em vez de sumir. Cada
 * integração grava na própria tabela (com `tenant_id`) — não há tabela
 * genérica de webhooks.
 *
 * Diferente do Pizzaria-App, segredo ausente NUNCA significa "aceitar sem
 * verificar": `isAuthentic()` deve retornar false.
 */
abstract class TenantWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::get() ?? throw TenantContextException::webhookWithoutTenant(static::class);

        $this->recordPayload($request, $tenant);

        if (! $this->isAuthentic($request, $tenant)) {
            return response()->json(['message' => 'Webhook não autenticado.'], $this->rejectionStatus());
        }

        return $this->process($request, $tenant);
    }

    /**
     * Grava o payload bruto (e o resultado de autenticação, se quiser) ANTES
     * de qualquer decisão. Roda sempre, mesmo para chamada não autêntica.
     */
    abstract protected function recordPayload(Request $request, Tenant $tenant): void;

    /**
     * Valida a autenticidade com as credenciais DO TENANT (HMAC, Basic Auth).
     * Credencial não configurada ⇒ false.
     */
    abstract protected function isAuthentic(Request $request, Tenant $tenant): bool;

    /**
     * Aplica o evento. Deve ser idempotente: provedores reenviam webhooks.
     */
    abstract protected function process(Request $request, Tenant $tenant): JsonResponse;

    /**
     * 401 para Basic Auth (Stone), 403 para assinatura HMAC (NFE.io).
     */
    protected function rejectionStatus(): int
    {
        return 401;
    }
}

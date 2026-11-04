<?php

namespace App\Support\Webhooks;

use App\Models\Tenant;

/**
 * Descobre a qual tenant pertence um webhook a partir do token opaco da URL
 * (`/webhooks/{provedor}/{token}`) — MT-02. Cada integração implementa o seu
 * (ex.: NFE.io lê `tenant_fiscal_settings.webhook_token`, Stone lê
 * `tenant_payment_integrations.webhook_token`) e o registra em
 * `config/webhooks.php` → `providers`.
 *
 * A implementação consulta com `withoutGlobalScopes()` (não há contexto de
 * tenant ainda) e devolve `null` para token desconhecido. O tenant deve ser o
 * do REGISTRO do token — nunca inferido do payload (MT-03).
 *
 * Atenção: o token é sensível a maiúsculas/minúsculas, mas um `WHERE token = ?`
 * no MySQL com collation `_ci` não é. Depois do lookup indexado, confirme com
 * `hash_equals($registro->token, $token)` (ou guarde/consulte o sha256 do token).
 */
interface WebhookTenantResolver
{
    public function resolve(string $token): ?Tenant;
}

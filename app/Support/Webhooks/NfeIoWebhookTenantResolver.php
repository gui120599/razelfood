<?php

namespace App\Support\Webhooks;

use App\Models\Tenant;
use App\Models\TenantFiscalSettings;

/**
 * Token da URL do webhook da NFE.io -> Tenant, pelo registro do token em
 * `tenant_fiscal_settings.webhook_token` (nunca pelo payload). Comparação case-sensitive
 * confirmada com hash_equals: a collation do MySQL ignora maiúsculas/minúsculas.
 */
class NfeIoWebhookTenantResolver implements WebhookTenantResolver
{
    public function resolve(string $token): ?Tenant
    {
        $settings = TenantFiscalSettings::query()->withoutGlobalScopes()->where('webhook_token', $token)->first();

        if ($settings === null || ! hash_equals((string) $settings->webhook_token, $token)) {
            return null;
        }

        return Tenant::query()->find($settings->tenant_id);
    }
}

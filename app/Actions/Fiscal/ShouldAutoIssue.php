<?php

namespace App\Actions\Fiscal;

use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Support\FeatureKey;

/**
 * Decide se o fechamento da venda deve disparar a emissão automática da NFC-e
 * (RN-FIS-12): o tenant tem a feature, a emissão está habilitada nos dados fiscais
 * e ao menos uma forma de pagamento da venda tem "emitir NFC-e automaticamente".
 * Só decide — a emissão revalida tudo de novo ao rodar.
 */
class ShouldAutoIssue
{
    public function __invoke(Tenant $tenant, Sale $sale): bool
    {
        if (! $tenant->hasFeature(FeatureKey::NFE_EMISSAO)) {
            return false;
        }

        $enabled = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->whereNotNull('enabled_at')->exists();

        if (! $enabled) {
            return false;
        }

        $optionIds = SalePayment::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->where('sale_id', $sale->id)
            ->pluck('payment_option_id')->filter();

        return PaymentOption::query()->withTrashed()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)->whereIn('id', $optionIds)
            ->where('auto_issue_fiscal_document', true)->exists();
    }
}

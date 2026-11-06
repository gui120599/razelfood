<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Jobs\CancelUnpaidSalesJob;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\FeatureKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mantém `tenants.pdv_enabled_at` coerente com a feature `pdv` do tenant
 * (plano + override), idempotente:
 *
 * - feature ligada e sem data → grava a data de corte (agora);
 * - feature desligada e com data → limpa a data (uma reativação futura começa
 *   uma data nova, então pedidos antigos não reaparecem como "a receber") e
 *   dispara o cancelamento das vendas SEM pagamento (CancelUnpaidSalesJob).
 *
 * Chamada pelos observers de Tenant (troca de plano) e TenantFeatureOverride,
 * e pelo comando agendado `sales:sync-pdv-activation`, que cobre o que observer
 * não vê (ex.: editar as features de um plano).
 */
class SyncPdvActivation
{
    public function __invoke(Tenant|int $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        // Recarrega com plano/overrides: o model recebido pode ter relações defasadas.
        $fresh = Tenant::query()->with(['plan.features', 'featureOverrides.feature'])->find($tenantId);

        if ($fresh === null) {
            return;
        }

        $enabled = $fresh->hasFeature(FeatureKey::PDV);
        $current = DB::table('tenants')->where('id', $fresh->id)->value('pdv_enabled_at');

        if ($enabled && $current === null) {
            DB::table('tenants')->where('id', $fresh->id)->whereNull('pdv_enabled_at')->update(['pdv_enabled_at' => now()]);
            Cache::forget("tenant:slug:{$fresh->slug}");

            return;
        }

        if ($enabled) {
            return;
        }

        // Atômico: observer e comando concorrentes não limpam (nem despacham) duas vezes.
        $cleared = DB::table('tenants')->where('id', $fresh->id)->whereNotNull('pdv_enabled_at')->update(['pdv_enabled_at' => null]);

        if ($cleared > 0) {
            Cache::forget("tenant:slug:{$fresh->slug}");
        }

        // Despacha na transição e, como rede de segurança, enquanto restar venda
        // Finalizada sem pagamento (ex.: o despacho anterior falhou). O job é único
        // por tenant, idempotente e revalida o estado ao rodar.
        if ($cleared > 0 || $this->hasUnpaidFinalizedSales($fresh->id)) {
            CancelUnpaidSalesJob::dispatch($fresh->id)->afterCommit();
        }
    }

    private function hasUnpaidFinalizedSales(int $tenantId): bool
    {
        return Sale::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', SaleStatus::Finalized)
            ->whereDoesntHave('payments')
            ->exists();
    }
}

<?php

namespace App\Console\Concerns;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Para commands agendados que precisam executar algo por tenant (MT-01):
 * itera os tenants ATIVOS que têm a feature ligada (plano + override, via
 * `Tenant::hasFeature()`, que já respeita `is_available`), roda o callback
 * em `TenantContext` e isola a falha de cada tenant — um erro é logado com
 * `tenant_id` e não interrompe os demais.
 *
 * Fora de um request HTTP o `Filament::getCurrentPanel()` é null; se o
 * callback consultar FilamentShield, chamar antes
 * `Filament::setCurrentPanel(Filament::getPanel('tenant'))`
 * (.ai/rules/commands.md).
 */
trait RunsForEachTenant
{
    /**
     * @param  Closure(Tenant): mixed  $callback
     * @return array{succeeded: int, failed: int}
     */
    protected function forEachTenantWithFeature(string $featureKey, Closure $callback): array
    {
        $succeeded = 0;
        $failed = 0;

        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->with(['plan.features', 'featureOverrides.feature'])
            ->chunkById(100, function ($tenants) use ($featureKey, $callback, &$succeeded, &$failed): void {
                foreach ($tenants as $tenant) {
                    if (! $tenant->hasFeature($featureKey)) {
                        continue;
                    }

                    try {
                        TenantContext::run($tenant, $callback);
                        $succeeded++;
                    } catch (Throwable $exception) {
                        $failed++;

                        Log::error("Falha ao processar o tenant #{$tenant->id} ({$tenant->slug}) na feature {$featureKey}.", [
                            'tenant_id' => $tenant->id,
                            'feature' => $featureKey,
                            'exception' => $exception,
                        ]);
                    }
                }
            });

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }
}

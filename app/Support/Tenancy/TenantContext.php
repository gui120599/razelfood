<?php

namespace App\Support\Tenancy;

use App\Exceptions\TenantContextException;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * Executa um trecho de código "dentro" de um tenant fora do ciclo de uma
 * requisição pública/painel: jobs, commands agendados e webhooks (MT-01).
 *
 * Replica a ponte que `ResolveTenantFromPath`/`ApplyTenantScopes` fazem
 * (CurrentTenant + container + URL::defaults + spatie teams) e, ao terminar
 * — com sucesso ou exceção —, restaura o estado ANTERIOR. Sem contexto
 * anterior (worker de fila, cron: o caso real) isso equivale a
 * `CurrentTenant::forget()`, então um job seguinte nunca herda o tenant do
 * anterior. Com contexto anterior (fila `sync` disparada dentro de uma
 * requisição de tenant) a requisição não perde o próprio contexto.
 */
final class TenantContext
{
    /**
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     *
     * @throws TenantContextException Quando o id informado não existe (ou foi excluído).
     */
    public static function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $tenant = self::resolve($tenant);
        $registrar = app(PermissionRegistrar::class);

        $previousTenant = CurrentTenant::get();
        $hadContainerInstance = app()->bound(Tenant::class);
        $previousContainerInstance = $hadContainerInstance ? app(Tenant::class) : null;
        $previousTeamId = $registrar->getPermissionsTeamId();
        $previousUrlTenant = URL::getDefaultParameters()['tenant'] ?? null;
        $previousSharedLogContext = Log::sharedContext();

        try {
            app()->instance(Tenant::class, $tenant);
            CurrentTenant::set($tenant);
            URL::defaults(['tenant' => $tenant->slug]);
            $registrar->setPermissionsTeamId($tenant->id);
            // shareContext (não withContext): vale para TODOS os canais, inclusive
            // os dedicados que as integrações abrem depois (Log::channel('stone')).
            Log::shareContext(['tenant_id' => $tenant->id]);

            return $callback($tenant);
        } finally {
            if ($previousTenant instanceof Tenant) {
                CurrentTenant::set($previousTenant);
            } else {
                CurrentTenant::forget();
            }

            Log::withoutContext(['tenant_id']);
            Log::flushSharedContext();

            if ($previousSharedLogContext !== []) {
                Log::shareContext($previousSharedLogContext);
            }

            if ($hadContainerInstance) {
                app()->instance(Tenant::class, $previousContainerInstance);
            } else {
                app()->forgetInstance(Tenant::class);
            }

            $registrar->setPermissionsTeamId($previousTeamId);
            URL::defaults(['tenant' => $previousUrlTenant]);
        }
    }

    private static function resolve(Tenant|int $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        return Tenant::query()->find($tenant) ?? throw TenantContextException::tenantNotFound($tenant);
    }
}

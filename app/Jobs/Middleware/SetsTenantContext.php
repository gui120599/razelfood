<?php

namespace App\Jobs\Middleware;

use App\Exceptions\TenantContextException;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware que roda o job inteiro dentro do contexto do tenant
 * informado em `$job->tenantId` e sempre restaura o contexto ao final
 * (MT-01). Um job sem `tenantId` válido falha explicitamente: seguir em
 * frente sem tenant faria o `TenantScope` virar no-op e o job enxergaria
 * os dados de todos os tenants.
 *
 * Normalmente aplicado pela trait `App\Jobs\Concerns\RunsInTenantContext`.
 */
class SetsTenantContext
{
    /**
     * @param  Closure(object): mixed  $next
     *
     * @throws TenantContextException
     */
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $job->tenantId ?? null;

        if (! is_int($tenantId) || $tenantId <= 0) {
            throw TenantContextException::missingTenantId($job::class);
        }

        return TenantContext::run($tenantId, fn () => $next($job));
    }
}

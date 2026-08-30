<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->extractSlug($request->getHost());

        if ($slug === null || in_array($slug, config('tenancy.reserved_slugs'), true)) {
            return $next($request); // rota central, sem tenant
        }

        // Eager load do plano/overrides junto da resolução do tenant: evita
        // N+1 quando o Filament chama Tenant::hasFeature() várias vezes por
        // requisição (uma por Resource verificado na navegação/canAccess).
        $resolveTenant = fn () => Tenant::with(['plan.features', 'featureOverrides.feature'])->where('slug', $slug)->first();

        $tenant = config('tenancy.cache.enabled')
            ? Cache::remember(
                "tenant:slug:{$slug}",
                now()->addMinutes(config('tenancy.cache.ttl_minutes')),
                $resolveTenant,
            )
            : $resolveTenant();

        if ($tenant === null) {
            abort(404, 'Estabelecimento não encontrado.');
        }

        if ($tenant->status !== TenantStatus::Active) {
            abort(503, 'Este cardápio está temporariamente indisponível.');
        }

        // Disponibiliza o tenant atual para toda a aplicação (global scope,
        // controllers, Blade, etc.) sem precisar passar por parâmetro.
        app()->instance(Tenant::class, $tenant);
        CurrentTenant::set($tenant);

        // Route::domain('{tenant}.'.base_domain) trata {tenant} como
        // parâmetro de rota comum — sem um default, qualquer route() para
        // esse domínio (ex.: redirect de login do Filament) quebra com
        // UrlGenerationException por parâmetro faltando.
        URL::defaults(['tenant' => $slug]);

        // spatie/laravel-permission (com 'teams' => true) não descobre o
        // tenant sozinho — precisa ser informado a cada requisição, senão
        // roles/permissions ficam soltas e furam o isolamento por tenant.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        return $next($request);
    }

    private function extractSlug(string $host): ?string
    {
        $baseDomain = config('tenancy.base_domain');

        if (! str_ends_with($host, ".{$baseDomain}")) {
            return null; // acesso direto pelo domínio base, sem subdomínio
        }

        return substr($host, 0, -1 * (strlen($baseDomain) + 1));
    }
}

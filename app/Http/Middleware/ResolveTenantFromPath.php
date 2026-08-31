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

/**
 * Resolve o tenant a partir do primeiro segmento do path (`/{tenant}/...`).
 *
 * Aplicado como middleware do grupo de rotas públicas do tenant em
 * routes/web.php — NÃO é mais um middleware global (era `IdentifyTenant`, que
 * lia o subdomínio do `Host`). O painel do Filament resolve o tenant pelo
 * mecanismo nativo `->tenant()` + `ApplyTenantScopes`, não por aqui.
 *
 * Como roda depois do routing, `$request->route('tenant')` já contém o slug
 * cru do primeiro segmento. Diferente do middleware antigo, este SEMPRE roda
 * em contexto de tenant: não existe o ramo "rota central, segue sem tenant".
 */
class ResolveTenantFromPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        // Defesa em profundidade: a constraint `->where('tenant', ...)` da
        // rota e a ordem de registro (rotas de sistema/painéis primeiro) já
        // impedem que um segmento reservado chegue até aqui.
        if (! is_string($slug) || $slug === '' || in_array($slug, config('tenancy.reserved_slugs'), true)) {
            abort(404);
        }

        // Eager load do plano/overrides junto da resolução do tenant: evita
        // N+1 quando o Filament chama Tenant::hasFeature() várias vezes por
        // requisição (uma por Resource verificado na navegação/canAccess).
        $resolveTenant = fn () => Tenant::with(['plan.features', 'featureOverrides.feature'])->where('slug', $slug)->first();

        $tenant = config('tenancy.cache.enabled')
            ? Cache::remember(
                "tenant:slug:{$slug}",
                now()->addMinutes((int) config('tenancy.cache.ttl_minutes')),
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

        // Route::prefix('{tenant}') trata {tenant} como parâmetro de rota
        // comum — sem um default, qualquer route() para essas rotas (ex.:
        // route('menu.index') sem args nas views) quebra com
        // UrlGenerationException por parâmetro faltando.
        URL::defaults(['tenant' => $slug]);

        // spatie/laravel-permission (com 'teams' => true) não descobre o
        // tenant sozinho — precisa ser informado a cada requisição, senão
        // roles/permissions ficam soltas e furam o isolamento por tenant.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        return $next($request);
    }
}

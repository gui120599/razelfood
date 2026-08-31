<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant middleware persistente do painel do tenant (registrado em
 * TenantPanelProvider via `->tenantMiddleware([...], isPersistent: true)`).
 *
 * O Filament já resolveu e validou o tenant do `{tenant}` da URL (route-model
 * binding + canAccessTenant) neste ponto. Este middleware só faz a ponte
 * entre `Filament::getTenant()` e a infraestrutura caseira que o resto da
 * aplicação usa (`CurrentTenant`, `TenantScope` global, spatie/permission
 * teams, `URL::defaults`) — antes essa ponte era o middleware `IdentifyTenant`
 * (resolução por subdomínio), removido na migração para tenancy por path.
 *
 * `isPersistent: true` garante que ele rode também nas requisições Livewire
 * AJAX do painel, não só no page load.
 */
class ApplyTenantScopes
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            app()->instance(Tenant::class, $tenant);
            CurrentTenant::set($tenant);
            URL::defaults(['tenant' => $tenant->slug]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        }

        return $next($request);
    }
}

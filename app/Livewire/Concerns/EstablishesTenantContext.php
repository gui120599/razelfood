<?php

namespace App\Livewire\Concerns;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restaura o contexto do tenant (CurrentTenant, URL::defaults, spatie-teams)
 * a cada requisição de um componente Livewire público do cardápio.
 *
 * Necessário porque `POST /livewire/update` NÃO passa pelo grupo
 * `Route::prefix('{tenant}')` de routes/web.php — a rota é `livewire/update`
 * pura, com middleware `web` só. Sem isto, no primeiro `wire:*` depois do
 * page load `CurrentTenant` fica null: o TenantScope para de filtrar e
 * `route('checkout.index')` estoura UrlGenerationException (falta `{tenant}`).
 *
 * O slug é guardado numa property pública (`$tenantSlug`), protegida contra
 * adulteração pelo checksum do snapshot do Livewire 3 — mudar o valor no
 * cliente invalida a requisição inteira.
 */
trait EstablishesTenantContext
{
    public string $tenantSlug = '';

    public function mountEstablishesTenantContext(): void
    {
        $slug = CurrentTenant::get()?->slug;

        abort_if($slug === null, 404);

        $this->tenantSlug = $slug;
    }

    public function bootedEstablishesTenantContext(): void
    {
        // No page load o middleware ResolveTenantFromPath já resolveu tudo.
        if (CurrentTenant::get()?->slug === $this->tenantSlug) {
            return;
        }

        $tenant = Tenant::query()
            ->with(['plan.features', 'featureOverrides.feature'])
            ->where('slug', $this->tenantSlug)
            ->first();

        abort_if($tenant === null || $tenant->status !== TenantStatus::Active, 404);

        app()->instance(Tenant::class, $tenant);
        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    }
}

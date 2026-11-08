<?php

namespace App\Providers;

use App\Actions\Sales\SyncPdvActivation;
use App\Enums\CentralRole;
use App\Filament\Tenant\Livewire\Orders\AddonPickerModal;
use App\Filament\Tenant\Livewire\Orders\ClientLookup;
use App\Filament\Tenant\Livewire\Orders\FlavorPickerModal;
use App\Filament\Tenant\Livewire\Orders\FulfillmentPicker;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
use App\Filament\Tenant\Livewire\Sales\SaleProductSelector;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super admin da Razel Tec (central_role "Plataforma"): acesso total,
        // inclusive aos Resources do painel de qualquer tenant (RN-44). O
        // Gate::before curto-circuita a autorização do Filament Shield para
        // esse usuário — no painel central ele já passava pelas policies
        // (CentralPanelPolicy), aqui isso vira explícito e cobre o painel do
        // tenant também. Retorna null para os demais (segue o fluxo normal).
        Gate::before(function ($user): ?bool {
            return ($user instanceof User && $user->hasCentralRole(CentralRole::Platform)) ? true : null;
        });

        // Componentes fora do namespace App\Livewire (embutidos na Page
        // AttendOrder do painel do tenant) não são auto-descobertos pela
        // convenção padrão do Livewire — registro explícito por tag.
        Livewire::component('tenant-order-product-catalog', ProductCatalogSelector::class);
        Livewire::component('tenant-order-flavor-picker', FlavorPickerModal::class);
        Livewire::component('tenant-order-addon-picker', AddonPickerModal::class);
        Livewire::component('tenant-order-client-lookup', ClientLookup::class);
        Livewire::component('tenant-order-fulfillment-picker', FulfillmentPicker::class);
        Livewire::component('tenant-sale-product-selector', SaleProductSelector::class);

        // Data de corte do PDV (Fase 1.0): acompanha a feature `pdv` do tenant. Troca de
        // plano e overrides são tratados na hora; o que não passa por aqui (editar as
        // features de um plano) é pego por `sales:sync-pdv-activation` a cada 5 min.
        Tenant::saved(function (Tenant $tenant): void {
            if ($tenant->wasRecentlyCreated || $tenant->wasChanged('plan_id')) {
                app(SyncPdvActivation::class)($tenant);
            }
        });
        TenantFeatureOverride::saved(fn (TenantFeatureOverride $override) => app(SyncPdvActivation::class)($override->tenant_id));
        TenantFeatureOverride::deleted(fn (TenantFeatureOverride $override) => app(SyncPdvActivation::class)($override->tenant_id));

        // Rate limit dos webhooks externos (/webhooks/...): um teto por IP +
        // token (o provedor legítimo) e outro, maior, só por IP (varredura de
        // tokens). Roda antes do middleware de token. Usa o store de cache padrão
        // (CACHE_STORE=database aqui): ver `cache.limiter` no relatório da Fase 0.
        RateLimiter::for('webhooks', fn (Request $request): array => [
            Limit::perMinute((int) config('webhooks.rate_limit_per_minute'))
                ->by('webhooks:'.$request->ip().'|'.$request->route('token')),
            Limit::perMinute((int) config('webhooks.rate_limit_per_ip_per_minute'))
                ->by('webhooks-ip:'.$request->ip()),
        ]);
    }
}

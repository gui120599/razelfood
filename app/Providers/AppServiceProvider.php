<?php

namespace App\Providers;

use App\Enums\CentralRole;
use App\Filament\Tenant\Livewire\Orders\AddonPickerModal;
use App\Filament\Tenant\Livewire\Orders\ClientLookup;
use App\Filament\Tenant\Livewire\Orders\FlavorPickerModal;
use App\Filament\Tenant\Livewire\Orders\FulfillmentPicker;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
    }
}

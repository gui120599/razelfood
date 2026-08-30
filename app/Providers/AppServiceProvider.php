<?php

namespace App\Providers;

use App\Filament\Tenant\Livewire\Orders\AddonPickerModal;
use App\Filament\Tenant\Livewire\Orders\ClientLookup;
use App\Filament\Tenant\Livewire\Orders\FlavorPickerModal;
use App\Filament\Tenant\Livewire\Orders\FulfillmentPicker;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
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

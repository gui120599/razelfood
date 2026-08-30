<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

class KitchenFilterPreferencesTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->gerente->assignRole('Gerente');
        $this->actingAs($this->gerente);
    }

    public function test_changing_a_filter_persists_it_as_a_preference(): void
    {
        Livewire::test(Kitchen::class)->set('quickFilter', 'delivery');

        $saved = UserPreference::valueFor($this->gerente, 'kitchen.filters');

        $this->assertSame('delivery', $saved['quickFilter']);
    }

    public function test_a_fresh_page_load_restores_the_saved_filters(): void
    {
        UserPreference::rememberFor($this->gerente, 'kitchen.filters', [
            'quickFilter' => 'preparing',
            'deliveryUserFilter' => null,
            'onlyLate' => true,
            'periodFrom' => null,
            'periodUntil' => null,
            'showCancelled' => false,
            'productionLineFilter' => null,
        ]);

        $page = Livewire::test(Kitchen::class)->instance();

        $this->assertSame('preparing', $page->quickFilter);
        $this->assertTrue($page->onlyLate);
    }

    public function test_saved_production_line_is_used_when_no_url_query_string_is_present(): void
    {
        UserPreference::rememberFor($this->gerente, 'kitchen.filters', [
            'quickFilter' => 'all',
            'productionLineFilter' => 42,
        ]);

        $page = Livewire::test(Kitchen::class)->instance();

        $this->assertSame(42, $page->productionLineFilter);
    }
}

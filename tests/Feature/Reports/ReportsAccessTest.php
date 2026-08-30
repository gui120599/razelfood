<?php

namespace Tests\Feature\Reports;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Reports;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * RF-31/RN-43: dashboard de relatórios gateado pela feature `relatorios`
 * (duas camadas — navegação + acesso direto) e pela permissão Shield.
 */
class ReportsAccessTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private function bootTenant(Tenant $tenant): void
    {
        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        app(SeedDefaultTenantRoles::class)($tenant);
    }

    private function makeTenant(?Plan $plan = null): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => ($plan ?? $this->planWithAllFeatures())->id,
        ]);
    }

    public function test_admin_with_feature_can_access(): void
    {
        $tenant = $this->makeTenant();
        $this->bootTenant($tenant);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('Admin');

        $this->actingAs($admin);

        $this->assertTrue(Reports::canAccess());
        Livewire::test(Reports::class)->assertOk();
    }

    public function test_manager_can_access_but_attendant_cannot(): void
    {
        $tenant = $this->makeTenant();
        $this->bootTenant($tenant);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole('Gerente');
        $this->actingAs($manager);
        $this->assertTrue(Reports::canAccess());

        $attendant = User::factory()->create(['tenant_id' => $tenant->id]);
        $attendant->assignRole('Atendente');
        $this->actingAs($attendant);
        $this->assertFalse(Reports::canAccess());
    }

    public function test_tenant_without_feature_cannot_access_or_see_navigation(): void
    {
        $plan = Plan::create(['name' => 'Sem relatórios', 'slug' => 'sem-relatorios-'.uniqid()]);
        foreach ([FeatureKey::CARDAPIO_DIGITAL, FeatureKey::CENTRAL_DE_PEDIDOS] as $key) {
            $plan->features()->attach(Feature::firstOrCreate(['key' => $key], ['name' => $key, 'is_available' => true]));
        }

        $tenant = $this->makeTenant($plan);
        $this->bootTenant($tenant);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertFalse(Reports::canAccess());
        $this->assertFalse(Reports::shouldRegisterNavigation());
    }
}

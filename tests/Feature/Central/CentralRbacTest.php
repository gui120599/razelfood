<?php

namespace Tests\Feature\Central;

use App\Enums\CentralRole;
use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\Features\FeatureResource;
use App\Filament\Resources\LocationSyncs\LocationSyncResource;
use App\Filament\Resources\Neighborhoods\NeighborhoodResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\States\StateResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-44: no painel central, "Suporte" ajuda os tenants e sincroniza
 * localidades, mas não mexe em planos nem no catálogo de features
 * (precificação). "Plataforma" tem acesso total.
 */
class CentralRbacTest extends TestCase
{
    use RefreshDatabase;

    private function centralUser(CentralRole $role): User
    {
        return User::factory()->create(['tenant_id' => null, 'central_role' => $role]);
    }

    public function test_platform_role_reaches_every_central_resource(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Platform));

        $this->get(TenantResource::getUrl('index'))->assertOk();
        $this->get(PlanResource::getUrl('index'))->assertOk();
        $this->get(FeatureResource::getUrl('index'))->assertOk();
        $this->get(LocationSyncResource::getUrl('index'))->assertOk();
        $this->get(StateResource::getUrl('index'))->assertOk();
        $this->get(CityResource::getUrl('index'))->assertOk();
        $this->get(NeighborhoodResource::getUrl('index'))->assertOk();
    }

    public function test_support_role_can_manage_tenants_and_locations_but_not_plans_or_features(): void
    {
        $this->actingAs($this->centralUser(CentralRole::Support));

        $this->get(TenantResource::getUrl('index'))->assertOk();
        $this->get(LocationSyncResource::getUrl('index'))->assertOk();
        $this->get(StateResource::getUrl('index'))->assertOk();
        $this->get(CityResource::getUrl('index'))->assertOk();
        $this->get(NeighborhoodResource::getUrl('index'))->assertOk();
        $this->get(PlanResource::getUrl('index'))->assertForbidden();
        $this->get(FeatureResource::getUrl('index'))->assertForbidden();
    }

    public function test_database_seeder_gives_the_super_admin_the_platform_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $superAdmin = User::query()->where('email', 'admin@razeltec.com.br')->first();

        $this->assertSame(CentralRole::Platform, $superAdmin->central_role);
    }
}

<?php

namespace Tests\Feature\Central;

use App\Filament\Resources\Features\Pages\CreateFeature;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\RelationManagers\FeatureOverridesRelationManager;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RF-40, RF-41: gestão de catálogo de features, composição de planos e
 * atribuição de plano/overrides a um tenant, tudo restrito ao painel central.
 */
class PlanFeatureManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $centralUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centralUser = $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        // O TenantForm renderiza os Selects de UF/Cidade (IBGE ao vivo).
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([])]);
    }

    public function test_central_user_can_create_a_feature(): void
    {
        Livewire::test(CreateFeature::class)
            ->fillForm([
                'key' => 'pdv',
                'name' => 'PDV',
                'is_available' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('features', ['key' => 'pdv', 'is_available' => false]);
    }

    public function test_central_user_can_create_a_plan_with_features_attached(): void
    {
        $cardapio = Feature::create(['key' => 'cardapio_digital', 'name' => 'Cardápio Digital', 'is_available' => true]);
        $configuracoes = Feature::create(['key' => 'configuracoes_estabelecimento', 'name' => 'Configurações', 'is_available' => true]);

        Livewire::test(CreatePlan::class)
            ->fillForm([
                'name' => 'Essencial',
                'slug' => 'essencial',
                'features' => [$cardapio->id, $configuracoes->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::where('slug', 'essencial')->firstOrFail();
        $this->assertSame([$cardapio->id, $configuracoes->id], $plan->features->pluck('id')->sort()->values()->all());
    }

    public function test_central_user_can_assign_a_plan_to_a_tenant(): void
    {
        $essencial = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $completo = Plan::create(['name' => 'Completo', 'slug' => 'completo']);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $essencial->id,
        ]);

        Livewire::test(EditTenant::class, ['record' => $tenant->id])
            ->fillForm(['plan_id' => $completo->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'plan_id' => $completo->id]);
    }

    public function test_central_user_can_create_a_feature_override_for_a_tenant(): void
    {
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $feature = Feature::create(['key' => 'pdv', 'name' => 'PDV', 'is_available' => true]);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);

        Livewire::test(FeatureOverridesRelationManager::class, [
            'ownerRecord' => $tenant,
            'pageClass' => EditTenant::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'feature_id' => $feature->id,
                'enabled' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('tenant_feature_overrides', [
            'tenant_id' => $tenant->id,
            'feature_id' => $feature->id,
            'enabled' => true,
        ]);
        $this->assertTrue($tenant->fresh()->hasFeature('pdv'));
    }
}

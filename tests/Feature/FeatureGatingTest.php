<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Filament\Tenant\Pages\ManageBusinessHours;
use App\Filament\Tenant\Pages\OrderSettings;
use App\Filament\Tenant\Resources\Categories\CategoryResource;
use App\Filament\Tenant\Resources\DeliveryOptions\DeliveryOptionResource;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\ProductionLines\ProductionLineResource;
use App\Filament\Tenant\Resources\Roles\RoleResource;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * RN-43: reforço de acesso por feature em duas camadas — esconde do menu
 * (shouldRegisterNavigation) e bloqueia acesso direto por URL (canAccess).
 * Usa CategoryResource (gated na feature cardapio_digital) como exemplo.
 */
class FeatureGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização por role/policy (Shield) não é o alvo deste teste —
        // isola o comportamento do gating por feature.
        Gate::before(fn () => true);
    }

    private function actingAsTenantUser(Tenant $tenant): void
    {
        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));

        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
    }

    public function test_resource_blocks_direct_url_access_when_tenant_lacks_the_feature(): void
    {
        $plan = Plan::create(['name' => 'Sem cardápio', 'slug' => 'sem-cardapio']);
        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $this->actingAsTenantUser($tenant);

        $this->assertFalse(CategoryResource::canAccess());
        $this->get(CategoryResource::getUrl('index'))->assertForbidden();
    }

    public function test_resource_hides_from_navigation_when_tenant_lacks_the_feature(): void
    {
        $plan = Plan::create(['name' => 'Sem cardápio', 'slug' => 'sem-cardapio']);
        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $this->actingAsTenantUser($tenant);

        $this->assertFalse(CategoryResource::shouldRegisterNavigation());
    }

    public function test_resource_allows_access_when_tenant_has_the_feature(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CARDAPIO_DIGITAL, 'name' => 'Cardápio Digital', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $plan->features()->attach($feature);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $this->actingAsTenantUser($tenant);

        $this->assertTrue(CategoryResource::canAccess());
        $this->assertTrue(CategoryResource::shouldRegisterNavigation());
        $this->get(CategoryResource::getUrl('index'))->assertOk();
    }

    public function test_resource_blocks_access_even_with_an_enabled_override_when_the_feature_is_unavailable(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CARDAPIO_DIGITAL, 'name' => 'Cardápio Digital', 'is_available' => false]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $plan->features()->attach($feature);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $tenant->featureOverrides()->create(['feature_id' => $feature->id, 'enabled' => true]);
        $this->actingAsTenantUser($tenant);

        $this->assertFalse(CategoryResource::canAccess());
        $this->get(CategoryResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Smoke test do outro grupo de feature (configuracoes_estabelecimento) —
     * os demais Resources gateados (Categories/Products/FlashPromotions em
     * cardapio_digital; DeliveryOptions/DeliveryZones/PaymentOptions em
     * configuracoes_estabelecimento) usam a mesma trait GatedByFeature, mas
     * isso confirma em tempo de execução que o segundo grupo está com a
     * chave certa e a rota registrada corretamente.
     */
    public function test_delivery_option_resource_is_blocked_without_configuracoes_estabelecimento(): void
    {
        $plan = Plan::create(['name' => 'Sem configurações', 'slug' => 'sem-configuracoes']);
        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $this->actingAsTenantUser($tenant);

        $this->assertFalse(DeliveryOptionResource::canAccess());
        $this->get(DeliveryOptionResource::getUrl('index'))->assertForbidden();
    }

    public function test_delivery_option_resource_is_allowed_with_configuracoes_estabelecimento(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CONFIGURACOES_ESTABELECIMENTO, 'name' => 'Configurações', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $plan->features()->attach($feature);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        $this->actingAsTenantUser($tenant);

        $this->assertTrue(DeliveryOptionResource::canAccess());
        $this->get(DeliveryOptionResource::getUrl('index'))->assertOk();
    }

    /**
     * Item do checklist de homologação (docs/modelagem-middleware-multitenant.md
     * seção 6): dois tenants em planos diferentes nunca vazam acesso um pro
     * outro, mesmo checando o mesmo Resource na mesma requisição de teste.
     */
    public function test_two_tenants_on_different_plans_are_isolated(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CARDAPIO_DIGITAL, 'name' => 'Cardápio Digital', 'is_available' => true]);

        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem cardápio', 'slug' => 'sem-cardapio']);

        $tenantA = Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]);
        $tenantB = Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]);

        $this->actingAsTenantUser($tenantA);
        $this->assertTrue(CategoryResource::canAccess());

        $this->actingAsTenantUser($tenantB);
        $this->assertFalse(CategoryResource::canAccess());
    }

    public function test_order_resource_is_gated_by_historico_pedidos(): void
    {
        $feature = Feature::create(['key' => FeatureKey::HISTORICO_PEDIDOS, 'name' => 'Histórico de Pedidos', 'is_available' => true]);
        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem histórico', 'slug' => 'sem-historico']);

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]));
        $this->assertTrue(OrderResource::canAccess());

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]));
        $this->assertFalse(OrderResource::canAccess());
        $this->get(OrderResource::getUrl('index'))->assertForbidden();
    }

    public function test_production_line_resource_is_gated_by_linhas_producao(): void
    {
        $feature = Feature::create(['key' => FeatureKey::LINHAS_PRODUCAO, 'name' => 'Linhas de Produção', 'is_available' => true]);
        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem linhas', 'slug' => 'sem-linhas']);

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]));
        $this->assertTrue(ProductionLineResource::canAccess());

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]));
        $this->assertFalse(ProductionLineResource::canAccess());
        $this->get(ProductionLineResource::getUrl('index'))->assertForbidden();
    }

    public function test_role_resource_is_gated_by_usuarios_permissoes(): void
    {
        $feature = Feature::create(['key' => FeatureKey::USUARIOS_PERMISSOES, 'name' => 'Usuários e Permissões', 'is_available' => true]);
        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem permissões', 'slug' => 'sem-permissoes']);

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]));
        $this->assertTrue(RoleResource::canAccess());

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]));
        $this->assertFalse(RoleResource::canAccess());
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Kitchen e OrderSettings usam HasPageShield (Filament Shield) — o gate
     * por feature precisa se combinar com ele via alias (`canAccess as
     * pageShieldCanAccess`), não substituí-lo. Ver Kitchen.php/OrderSettings.php.
     */
    public function test_kitchen_page_is_gated_by_central_de_pedidos(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CENTRAL_DE_PEDIDOS, 'name' => 'Central de Pedidos', 'is_available' => true]);
        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem central', 'slug' => 'sem-central']);

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]));
        $this->assertTrue(Kitchen::canAccess());
        $this->assertTrue(Kitchen::shouldRegisterNavigation());

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]));
        $this->assertFalse(Kitchen::canAccess());
        $this->assertFalse(Kitchen::shouldRegisterNavigation());
    }

    public function test_order_settings_page_is_gated_by_configuracoes_pedidos(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CONFIGURACOES_PEDIDOS, 'name' => 'Configurações de Pedidos', 'is_available' => true]);
        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem configurações de pedidos', 'slug' => 'sem-config-pedidos']);

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]));
        $this->assertTrue(OrderSettings::canAccess());

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]));
        $this->assertFalse(OrderSettings::canAccess());
    }

    public function test_business_hours_page_is_gated_by_configuracoes_estabelecimento(): void
    {
        $feature = Feature::create(['key' => FeatureKey::CONFIGURACOES_ESTABELECIMENTO, 'name' => 'Configurações do Estabelecimento', 'is_available' => true]);
        $planWithFeature = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $planWithFeature->features()->attach($feature);
        $planWithoutFeature = Plan::create(['name' => 'Sem configurações', 'slug' => 'sem-config-estab']);

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $planWithFeature->id,
        ]));
        $this->assertTrue(ManageBusinessHours::canAccess());
        $this->assertTrue(ManageBusinessHours::shouldRegisterNavigation());

        $this->actingAsTenantUser(Tenant::create([
            'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999998', 'plan_id' => $planWithoutFeature->id,
        ]));
        $this->assertFalse(ManageBusinessHours::canAccess());
        $this->assertFalse(ManageBusinessHours::shouldRegisterNavigation());
    }
}

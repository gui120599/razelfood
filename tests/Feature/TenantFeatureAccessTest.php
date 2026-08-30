<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-39 a RN-42: resolução da feature efetiva de um tenant (plano, override
 * e reserva de roadmap indisponível), ver docs/modelagem-middleware-multitenant.md seção 3.1.1.
 */
class TenantFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_has_feature_included_in_its_plan(): void
    {
        $feature = Feature::create(['key' => 'cardapio_digital', 'name' => 'Cardápio Digital', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $plan->features()->attach($feature);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);

        $this->assertTrue($tenant->hasFeature('cardapio_digital'));
    }

    public function test_tenant_does_not_have_feature_outside_its_plan(): void
    {
        $feature = Feature::create(['key' => 'pdv', 'name' => 'PDV', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);

        $this->assertFalse($tenant->hasFeature('pdv'));
    }

    public function test_disabled_override_wins_over_plan(): void
    {
        $feature = Feature::create(['key' => 'cardapio_digital', 'name' => 'Cardápio Digital', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $plan->features()->attach($feature);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature_id' => $feature->id, 'enabled' => false]);

        $this->assertFalse($tenant->hasFeature('cardapio_digital'));
    }

    public function test_enabled_override_wins_over_plan(): void
    {
        $feature = Feature::create(['key' => 'pdv', 'name' => 'PDV', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature_id' => $feature->id, 'enabled' => true]);

        $this->assertTrue($tenant->hasFeature('pdv'));
    }

    public function test_unavailable_feature_is_never_granted_even_with_plan_and_override(): void
    {
        $feature = Feature::create(['key' => 'nfe_emissao', 'name' => 'Emissão de NF-e', 'is_available' => false]);
        $plan = Plan::create(['name' => 'Completo', 'slug' => 'completo']);
        $plan->features()->attach($feature);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999', 'plan_id' => $plan->id,
        ]);
        TenantFeatureOverride::create(['tenant_id' => $tenant->id, 'feature_id' => $feature->id, 'enabled' => true]);

        $this->assertFalse($tenant->hasFeature('nfe_emissao'));
    }

    public function test_tenant_without_plan_has_no_features(): void
    {
        Feature::create(['key' => 'cardapio_digital', 'name' => 'Cardápio Digital', 'is_available' => true]);

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'whatsapp_number' => '5511999999999',
        ]);

        $this->assertFalse($tenant->hasFeature('cardapio_digital'));
    }
}

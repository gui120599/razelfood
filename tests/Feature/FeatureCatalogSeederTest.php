<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\FeatureKey;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D-02: PDV/venda, NFC-e e Stone Connect estão em escopo como features de
 * plano, DESLIGADAS por padrão. `pdv` virou liberável ao fim da Fase 1.0 (só o
 * plano Completo ou um override a ligam); NFC-e e Stone Connect seguem
 * reservadas (is_available = false) até a fase 2 (a `nfe_emissao` foi liberada ao fim da 1.3).
 */
class FeatureCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function plannedFeatureKeys(): array
    {
        return [
            'stone_connect' => [FeatureKey::STONE_CONNECT],
        ];
    }

    #[DataProvider('plannedFeatureKeys')]
    public function test_planned_feature_is_seeded_as_unavailable(string $key): void
    {
        $this->seed(FeatureSeeder::class);

        $feature = Feature::where('key', $key)->first();

        $this->assertNotNull($feature, "A feature {$key} deveria existir no catálogo.");
        $this->assertFalse($feature->is_available);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(FeatureSeeder::class);
        $total = Feature::count();

        $this->seed(FeatureSeeder::class);

        $this->assertSame($total, Feature::count());
        $this->assertSame(1, Feature::where('key', FeatureKey::STONE_CONNECT)->count());
    }

    #[DataProvider('plannedFeatureKeys')]
    public function test_tenant_never_gets_a_planned_feature_even_on_the_full_plan_or_with_an_override(string $key): void
    {
        $this->seed([FeatureSeeder::class, PlanSeeder::class]);

        $completo = Plan::where('slug', 'completo')->first();
        $feature = Feature::where('key', $key)->first();
        $this->assertTrue($completo->features->contains('id', $feature->id), 'O plano Completo lista todas as features.');

        $tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste', 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => $completo->id,
        ]);

        $this->assertFalse($tenant->hasFeature($key));

        $tenant->featureOverrides()->create(['feature_id' => $feature->id, 'enabled' => true]);

        $this->assertFalse($tenant->fresh()->hasFeature($key));
    }

    public function test_essencial_plan_does_not_include_the_planned_features(): void
    {
        $this->seed([FeatureSeeder::class, PlanSeeder::class]);

        $keys = Plan::where('slug', 'essencial')->first()->features->pluck('key');

        $this->assertFalse($keys->contains(FeatureKey::PDV));
        $this->assertFalse($keys->contains(FeatureKey::NFE_EMISSAO));
        $this->assertFalse($keys->contains(FeatureKey::STONE_CONNECT));
    }

    public function test_pdv_is_available_but_only_the_full_plan_or_an_override_turn_it_on(): void
    {
        $this->seed([FeatureSeeder::class, PlanSeeder::class]);

        $pdv = Feature::where('key', FeatureKey::PDV)->first();
        $this->assertTrue($pdv->is_available);

        $makeTenant = fn (string $slug, string $plan): Tenant => Tenant::create([
            'name' => $slug, 'slug' => $slug, 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => Plan::where('slug', $plan)->value('id'),
        ]);

        $essencial = $makeTenant('essencial-teste', 'essencial');
        $completo = $makeTenant('completo-teste', 'completo');

        $this->assertFalse($essencial->hasFeature(FeatureKey::PDV));
        $this->assertTrue($completo->hasFeature(FeatureKey::PDV));

        $essencial->featureOverrides()->create(['feature_id' => $pdv->id, 'enabled' => true]);

        $this->assertTrue($essencial->fresh()->hasFeature(FeatureKey::PDV));
    }

    public function test_nfe_emissao_is_available_after_the_fiscal_lifecycle_phase_but_only_the_full_plan_or_an_override_turn_it_on(): void
    {
        $this->seed([FeatureSeeder::class, PlanSeeder::class]);

        $nfe = Feature::where('key', FeatureKey::NFE_EMISSAO)->first();
        $this->assertTrue($nfe->is_available);

        $makeTenant = fn (string $slug, string $plan): Tenant => Tenant::create([
            'name' => $slug, 'slug' => $slug, 'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999', 'plan_id' => Plan::where('slug', $plan)->value('id'),
        ]);

        $essencial = $makeTenant('essencial-nfe', 'essencial');
        $completo = $makeTenant('completo-nfe', 'completo');

        $this->assertFalse($essencial->hasFeature(FeatureKey::NFE_EMISSAO));
        $this->assertTrue($completo->hasFeature(FeatureKey::NFE_EMISSAO));

        $essencial->featureOverrides()->create(['feature_id' => $nfe->id, 'enabled' => true]);

        $this->assertTrue($essencial->fresh()->hasFeature(FeatureKey::NFE_EMISSAO));
    }

    public function test_the_release_migration_makes_the_feature_available_and_is_reversible(): void
    {
        $this->seed(FeatureSeeder::class);
        Feature::where('key', FeatureKey::NFE_EMISSAO)->update(['is_available' => false]);

        $migration = require database_path('migrations/2026_11_06_095134_release_nfe_emissao_feature.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Feature::where('key', FeatureKey::NFE_EMISSAO)->first()->is_available);

        $migration->down();

        $this->assertFalse(Feature::where('key', FeatureKey::NFE_EMISSAO)->first()->is_available);
    }
}

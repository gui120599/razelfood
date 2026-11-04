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
 * Fase 0 (D-02): PDV/venda, NFC-e e Stone Connect estão em escopo como
 * features de plano, mas DESLIGADAS por padrão (is_available = false) — o
 * catálogo só as reserva; nenhum tenant as recebe antes da fase respectiva.
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
            'pdv' => [FeatureKey::PDV],
            'nfe_emissao' => [FeatureKey::NFE_EMISSAO],
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
}

<?php

namespace Tests\Feature\Tenancy;

use App\Console\Concerns\RunsForEachTenant;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * MT-01: command agendado que itera tenants ativos com a feature ligada,
 * gravando em cada um sem cruzar dados e isolando a falha por tenant.
 */
class RunsForEachTenantTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CurrentTenant é estático: parte de estado limpo, sem herdar de outro teste.
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        ProbeTenantsCommand::$failOnSlug = null;
        Artisan::registerCommand(new ProbeTenantsCommand);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    private function makeTenant(string $slug, ?Plan $plan, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => $status,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $plan?->id,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function tenantIdsWithCommandCategory(): array
    {
        return Category::withoutGlobalScopes()
            ->where('name', 'via-command')
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->all();
    }

    public function test_command_writes_to_each_active_tenant_with_the_feature_without_crossing(): void
    {
        $plan = $this->planWithAllFeatures();
        $semFeature = Plan::create(['name' => 'Vazio', 'slug' => 'vazio-teste']);

        $a = $this->makeTenant('lazzopizza', $plan);
        $b = $this->makeTenant('emporiodapizza', $plan);
        $this->makeTenant('suspensa', $plan, TenantStatus::Suspended);
        $this->makeTenant('semfeature', $semFeature);
        $this->makeTenant('semplano', null);

        $this->artisan('probe:tenants')->assertExitCode(0);

        $this->assertSame([$a->id, $b->id], $this->tenantIdsWithCommandCategory());
        $this->assertNull(CurrentTenant::get());
        $this->assertSame(
            [$a->id => [$a->id], $b->id => [$b->id]],
            ProbeTenantsCommand::$visibleTenantIdsByTenant,
        );
    }

    public function test_failure_in_one_tenant_does_not_stop_the_others_and_is_logged_with_tenant_id(): void
    {
        $plan = $this->planWithAllFeatures();
        $a = $this->makeTenant('lazzopizza', $plan);
        $b = $this->makeTenant('emporiodapizza', $plan);
        ProbeTenantsCommand::$failOnSlug = 'lazzopizza';

        // Partial mock: só `error` é interceptado; o resto do LogManager é real.
        Log::partialMock()
            ->shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => ($context['tenant_id'] ?? null) === $a->id
                && ($context['exception'] ?? null) instanceof RuntimeException);

        $this->artisan('probe:tenants')->assertExitCode(1);

        $this->assertSame([$b->id], $this->tenantIdsWithCommandCategory());
        $this->assertNull(CurrentTenant::get());
    }

    public function test_tenant_override_can_disable_the_feature(): void
    {
        $plan = $this->planWithAllFeatures();
        $a = $this->makeTenant('lazzopizza', $plan);
        $b = $this->makeTenant('emporiodapizza', $plan);

        $feature = $plan->features()->where('key', FeatureKey::RELATORIOS)->first();
        $b->featureOverrides()->create(['feature_id' => $feature->id, 'enabled' => false]);

        $this->artisan('probe:tenants')->assertExitCode(0);

        $this->assertSame([$a->id], $this->tenantIdsWithCommandCategory());
    }

    public function test_tenant_override_can_enable_a_feature_the_plan_lacks(): void
    {
        $plan = $this->planWithAllFeatures();
        $semFeature = Plan::create(['name' => 'Vazio', 'slug' => 'vazio-teste']);
        $a = $this->makeTenant('lazzopizza', $plan);
        $b = $this->makeTenant('emporiodapizza', $semFeature);

        $feature = $plan->features()->where('key', FeatureKey::RELATORIOS)->first();
        $b->featureOverrides()->create(['feature_id' => $feature->id, 'enabled' => true]);

        $this->artisan('probe:tenants')->assertExitCode(0);

        $this->assertSame([$a->id, $b->id], $this->tenantIdsWithCommandCategory());
    }
}

/**
 * Command de teste que usa o helper e grava uma categoria por tenant.
 */
class ProbeTenantsCommand extends Command
{
    use RunsForEachTenant;

    public static ?string $failOnSlug = null;

    /** @var array<int, array<int, int>> */
    public static array $visibleTenantIdsByTenant = [];

    protected $signature = 'probe:tenants';

    protected $description = 'Command de teste do RunsForEachTenant.';

    public function handle(): int
    {
        self::$visibleTenantIdsByTenant = [];

        $result = $this->forEachTenantWithFeature(FeatureKey::RELATORIOS, function (Tenant $tenant): void {
            if ($tenant->slug === self::$failOnSlug) {
                throw new RuntimeException('falha simulada');
            }

            Category::create(['name' => 'via-command']);

            self::$visibleTenantIdsByTenant[$tenant->id] = Category::query()
                ->where('name', 'via-command')
                ->pluck('tenant_id')
                ->all();
        });

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

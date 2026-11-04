<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Exceptions\TenantContextException;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Category;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MT-01: um job só enxerga e grava dados do tenant para o qual foi
 * disparado, e nunca deixa o contexto vazar para o job seguinte.
 */
class TenantJobContextTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('lazzopizza');
        $this->tenantB = $this->makeTenant('emporiodapizza');

        Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenantA->id, 'name' => 'categoria-a']);
        Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenantB->id, 'name' => 'categoria-b']);

        ProbeCategoriesJob::reset();
        $this->forgetTenantState();
    }

    protected function tearDown(): void
    {
        $this->forgetTenantState();

        parent::tearDown();
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);
    }

    private function forgetTenantState(): void
    {
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_job_only_reads_and_writes_data_of_its_own_tenant(): void
    {
        ProbeCategoriesJob::dispatchSync($this->tenantA->id);

        $this->assertSame(['categoria-a'], ProbeCategoriesJob::$visibleNames);
        $this->assertSame($this->tenantA->id, ProbeCategoriesJob::$currentTenantId);
        $this->assertSame($this->tenantA->id, ProbeCategoriesJob::$permissionsTeamId);

        $created = Category::withoutGlobalScopes()->where('name', 'criada-no-job')->get();
        $this->assertCount(1, $created);
        $this->assertSame($this->tenantA->id, $created->first()->tenant_id);

        ProbeCategoriesJob::dispatchSync($this->tenantB->id);

        $this->assertEqualsCanonicalizing(['categoria-b'], ProbeCategoriesJob::$visibleNames);
        $this->assertSame(
            [$this->tenantA->id, $this->tenantB->id],
            Category::withoutGlobalScopes()->where('name', 'criada-no-job')->orderBy('tenant_id')->pluck('tenant_id')->all(),
        );
    }

    public function test_context_is_forgotten_after_the_job(): void
    {
        ProbeCategoriesJob::dispatchSync($this->tenantA->id);

        $this->assertNull(CurrentTenant::get());
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
        $this->assertFalse(app()->bound(Tenant::class));
        // Sem contexto, a query volta a enxergar todos os tenants — por isso o job nunca pode rodar sem tenant.
        $this->assertGreaterThanOrEqual(2, Category::count());
    }

    public function test_context_is_forgotten_even_when_the_job_throws(): void
    {
        try {
            FailingProbeJob::dispatchSync($this->tenantA->id);
            $this->fail('O job deveria ter lançado a exceção.');
        } catch (RuntimeException $exception) {
            $this->assertSame('falha do job', $exception->getMessage());
        }

        $this->assertNull(CurrentTenant::get());
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_job_without_tenant_id_fails_explicitly_and_never_runs(): void
    {
        try {
            JobWithoutTenantId::dispatchSync();
            $this->fail('O job sem tenantId deveria falhar.');
        } catch (TenantContextException $exception) {
            $this->assertStringContainsString('tenantId', $exception->getMessage());
        }

        $this->assertFalse(JobWithoutTenantId::$handled);
        $this->assertNull(CurrentTenant::get());
    }

    public function test_job_with_unknown_tenant_id_fails_explicitly(): void
    {
        $this->expectException(TenantContextException::class);

        try {
            ProbeCategoriesJob::dispatchSync(999999);
        } finally {
            $this->assertNull(ProbeCategoriesJob::$currentTenantId);
        }
    }

    public function test_job_for_soft_deleted_tenant_fails_explicitly(): void
    {
        $this->tenantA->delete();

        $this->expectException(TenantContextException::class);

        ProbeCategoriesJob::dispatchSync($this->tenantA->id);
    }

    public function test_previous_context_is_restored_instead_of_wiped(): void
    {
        CurrentTenant::set($this->tenantA);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        URL::defaults(['tenant' => $this->tenantA->slug]);

        ProbeCategoriesJob::dispatchSync($this->tenantB->id);

        $this->assertSame($this->tenantB->id, ProbeCategoriesJob::$currentTenantId);
        $this->assertSame($this->tenantA->id, CurrentTenant::id());
        $this->assertSame($this->tenantA->id, app(PermissionRegistrar::class)->getPermissionsTeamId());
        $this->assertSame($this->tenantA->slug, URL::getDefaultParameters()['tenant']);
    }

    public function test_log_context_carries_tenant_id_during_the_job_only(): void
    {
        $this->assertArrayNotHasKey('tenant_id', Log::sharedContext());

        TenantContext::run($this->tenantA, function (): void {
            $this->assertSame($this->tenantA->id, Log::sharedContext()['tenant_id']);

            TenantContext::run($this->tenantB, function (): void {
                $this->assertSame($this->tenantB->id, Log::sharedContext()['tenant_id']);
            });

            $this->assertSame($this->tenantA->id, Log::sharedContext()['tenant_id']);
        });

        $this->assertArrayNotHasKey('tenant_id', Log::sharedContext());
    }

    public function test_nested_runs_restore_each_previous_context(): void
    {
        TenantContext::run($this->tenantA, function (): void {
            TenantContext::run($this->tenantB, function (): void {
                $this->assertSame($this->tenantB->id, CurrentTenant::id());
            });

            $this->assertSame($this->tenantA->id, CurrentTenant::id());
            $this->assertSame($this->tenantA->id, app(PermissionRegistrar::class)->getPermissionsTeamId());
        });

        $this->assertNull(CurrentTenant::get());
    }

    public function test_tenant_context_returns_the_callback_value_and_accepts_a_model(): void
    {
        $result = TenantContext::run($this->tenantB, fn (Tenant $tenant): array => [
            $tenant->id,
            Category::pluck('name')->all(),
        ]);

        $this->assertSame([$this->tenantB->id, ['categoria-b']], $result);
        $this->assertNull(CurrentTenant::get());
    }
}

/**
 * Job de teste: registra o que enxergou em propriedades estáticas e cria uma
 * categoria SEM informar tenant_id (quem preenche é o BelongsToTenant).
 */
class ProbeCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    /** @var array<int, string> */
    public static array $visibleNames = [];

    public static ?int $currentTenantId = null;

    public static ?int $permissionsTeamId = null;

    public function __construct(public readonly int $tenantId) {}

    public static function reset(): void
    {
        self::$visibleNames = [];
        self::$currentTenantId = null;
        self::$permissionsTeamId = null;
    }

    public function handle(): void
    {
        self::$visibleNames = Category::query()->orderBy('id')->pluck('name')->all();
        self::$currentTenantId = CurrentTenant::id();
        self::$permissionsTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        Category::create(['name' => 'criada-no-job']);
    }
}

class FailingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public function __construct(public readonly int $tenantId) {}

    public function handle(): void
    {
        throw new RuntimeException('falha do job');
    }
}

class JobWithoutTenantId implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public static bool $handled = false;

    public function handle(): void
    {
        self::$handled = true;
    }
}

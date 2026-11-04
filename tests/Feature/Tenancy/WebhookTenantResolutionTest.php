<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Http\Controllers\Webhooks\TenantWebhookController;
use App\Models\Category;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Webhooks\WebhookTenantResolver;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MT-02: infraestrutura de webhooks externos — prefixo /webhooks fora do
 * grupo {tenant}, sem sessão/CSRF, com rate limit, tenant resolvido por
 * token opaco e o padrão "grava o payload antes, decide 401/403 depois".
 * Usa uma rota/provedor FAKE: as colunas de token reais nascem nas fases
 * 1.3 e 2.3.
 */
class WebhookTenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // CurrentTenant é estático: parte de estado limpo, sem herdar de outro teste.
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->tenantA = $this->makeTenant('lazzopizza');
        $this->tenantB = $this->makeTenant('emporiodapizza');

        FakeWebhookResolver::$tokens = [self::TOKEN_A => $this->tenantA->id, self::TOKEN_B => $this->tenantB->id];
        FakeWebhookController::reset();

        config(['webhooks.providers.fake' => FakeWebhookResolver::class]);

        // Mesmo grupo de bootstrap/app.php (`then:`) — sem o grupo `web`.
        Route::middleware('throttle:webhooks')->prefix('webhooks')->group(function (): void {
            Route::post('/fake/{token}', FakeWebhookController::class)->middleware('webhook.tenant:fake');
            Route::post('/ghost/{token}', FakeWebhookController::class)->middleware('webhook.tenant:ghost');
        });
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    private function makeTenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => $status,
            'whatsapp_number' => '5511999999999',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function validAuthHeaders(Tenant $tenant): array
    {
        return ['X-Fake-Secret' => 'segredo-'.$tenant->slug];
    }

    public function test_token_resolves_its_own_tenant_and_isolates_the_two_tenants(): void
    {
        $this->postJson('/webhooks/fake/'.self::TOKEN_A, ['evento' => 'a'], $this->validAuthHeaders($this->tenantA))
            ->assertOk()
            ->assertJson(['tenant' => $this->tenantA->id]);

        $this->postJson('/webhooks/fake/'.self::TOKEN_B, ['evento' => 'b'], $this->validAuthHeaders($this->tenantB))
            ->assertOk()
            ->assertJson(['tenant' => $this->tenantB->id]);

        $this->assertSame(
            [$this->tenantA->id, $this->tenantB->id],
            Category::withoutGlobalScopes()->where('name', 'processado')->orderBy('tenant_id')->pluck('tenant_id')->all(),
        );
        $this->assertSame([$this->tenantA->id => 1, $this->tenantB->id => 1], FakeWebhookController::$visibleCategoriesByTenant);
    }

    public function test_token_of_one_tenant_cannot_be_used_with_the_credentials_of_another(): void
    {
        // O tenant vem do TOKEN; a autenticação usa as credenciais desse tenant.
        $this->postJson('/webhooks/fake/'.self::TOKEN_A, [], $this->validAuthHeaders($this->tenantB))
            ->assertUnauthorized();

        $this->assertSame(0, Category::withoutGlobalScopes()->where('name', 'processado')->count());
    }

    public function test_unknown_well_formed_token_is_not_found_and_records_nothing(): void
    {
        $this->postJson('/webhooks/fake/'.str_repeat('z', 40), ['evento' => 'x'])->assertNotFound();

        $this->assertSame([], FakeWebhookController::$events);
        $this->assertNull(CurrentTenant::get());
    }

    public function test_malformed_token_is_not_found_before_touching_the_resolver(): void
    {
        FakeWebhookResolver::$lookups = 0;

        $this->postJson('/webhooks/fake/curto', [])->assertNotFound();
        $this->postJson('/webhooks/fake/'.str_repeat('a', 129), [])->assertNotFound();
        $this->postJson('/webhooks/fake/'.str_repeat('a', 39).'.', [])->assertNotFound();

        $this->assertSame(0, FakeWebhookResolver::$lookups);
        $this->assertSame([], FakeWebhookController::$events);
    }

    public function test_payload_is_recorded_before_the_authentication_decision(): void
    {
        $this->postJson('/webhooks/fake/'.self::TOKEN_A, ['evento' => 'forjado'], ['X-Fake-Secret' => 'errado'])
            ->assertUnauthorized();

        // Gravou (com o tenant certo) ANTES de decidir; não processou.
        $this->assertSame(['recorded', 'authenticated:no'], FakeWebhookController::$events);
        $this->assertSame(
            [['tenant_id' => $this->tenantA->id, 'payload' => ['evento' => 'forjado']]],
            FakeWebhookController::$recorded,
        );
        $this->assertSame(0, Category::withoutGlobalScopes()->where('name', 'processado')->count());
    }

    public function test_authentic_call_runs_record_then_authenticate_then_process_in_order(): void
    {
        $this->postJson('/webhooks/fake/'.self::TOKEN_A, ['evento' => 'ok'], $this->validAuthHeaders($this->tenantA))->assertOk();

        $this->assertSame(['recorded', 'authenticated:yes', 'processed'], FakeWebhookController::$events);
    }

    public function test_rejection_status_is_defined_by_the_integration(): void
    {
        FakeWebhookController::$rejectionStatus = 403;

        $this->postJson('/webhooks/fake/'.self::TOKEN_A, [], ['X-Fake-Secret' => 'errado'])->assertForbidden();
        $this->assertSame(['recorded', 'authenticated:no'], FakeWebhookController::$events);
    }

    public function test_tenant_context_is_released_after_the_request(): void
    {
        $this->postJson('/webhooks/fake/'.self::TOKEN_A, [], $this->validAuthHeaders($this->tenantA))->assertOk();

        $this->assertNull(CurrentTenant::get());
        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
        $this->assertFalse(app()->bound(Tenant::class));
    }

    public function test_suspended_tenant_is_still_resolved_but_deleted_tenant_is_not(): void
    {
        $this->tenantA->update(['status' => TenantStatus::Suspended]);

        $this->postJson('/webhooks/fake/'.self::TOKEN_A, [], $this->validAuthHeaders($this->tenantA))
            ->assertOk()
            ->assertJson(['tenant' => $this->tenantA->id]);

        $this->tenantB->delete();
        FakeWebhookResolver::$returnTrashed = true;

        $this->postJson('/webhooks/fake/'.self::TOKEN_B, [], $this->validAuthHeaders($this->tenantB))->assertNotFound();
    }

    public function test_webhook_routes_have_no_session_or_csrf_middleware_and_are_throttled(): void
    {
        $route = Route::getRoutes()->match(Request::create('/webhooks/fake/'.self::TOKEN_A, 'POST'));
        $middleware = $route->gatherMiddleware();

        $this->assertNotContains('web', $middleware);
        $this->assertContains('throttle:webhooks', $middleware);
        $this->assertContains('webhook.tenant:fake', $middleware);
        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertNotContains(VerifyCsrfToken::class, $middleware);
    }

    public function test_requests_beyond_the_rate_limit_get_429(): void
    {
        config(['webhooks.rate_limit_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/webhooks/fake/'.self::TOKEN_A, [], $this->validAuthHeaders($this->tenantA))->assertOk();
        }

        $this->postJson('/webhooks/fake/'.self::TOKEN_A, [], $this->validAuthHeaders($this->tenantA))->assertTooManyRequests();
        // Outro token (outro tenant) tem o próprio balde por IP+token.
        $this->postJson('/webhooks/fake/'.self::TOKEN_B, [], $this->validAuthHeaders($this->tenantB))->assertOk();
    }

    public function test_get_on_a_webhook_route_is_not_allowed_and_never_falls_into_the_tenant_menu(): void
    {
        $this->get('/webhooks/fake/'.self::TOKEN_A)->assertStatus(405);
        $this->get('/webhooks')->assertNotFound();
    }

    public function test_provider_without_registered_resolver_fails_loudly(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);

        $this->postJson('/webhooks/ghost/'.self::TOKEN_A, []);
    }
}

class FakeWebhookResolver implements WebhookTenantResolver
{
    /** @var array<string, int> */
    public static array $tokens = [];

    public static int $lookups = 0;

    public static bool $returnTrashed = false;

    public function resolve(string $token): ?Tenant
    {
        self::$lookups++;

        $tenantId = self::$tokens[$token] ?? null;

        if ($tenantId === null) {
            return null;
        }

        $query = Tenant::query();

        return (self::$returnTrashed ? $query->withTrashed() : $query)->find($tenantId);
    }
}

class FakeWebhookController extends TenantWebhookController
{
    /** @var array<int, string> */
    public static array $events = [];

    /** @var array<int, array{tenant_id: int|null, payload: array<string, mixed>}> */
    public static array $recorded = [];

    /** @var array<int, int> */
    public static array $visibleCategoriesByTenant = [];

    public static int $rejectionStatus = 401;

    public static function reset(): void
    {
        self::$events = [];
        self::$recorded = [];
        self::$visibleCategoriesByTenant = [];
        self::$rejectionStatus = 401;
        FakeWebhookResolver::$lookups = 0;
        FakeWebhookResolver::$returnTrashed = false;
    }

    protected function recordPayload(Request $request, Tenant $tenant): void
    {
        self::$events[] = 'recorded';
        self::$recorded[] = ['tenant_id' => CurrentTenant::id(), 'payload' => $request->all()];
    }

    protected function isAuthentic(Request $request, Tenant $tenant): bool
    {
        $authentic = hash_equals('segredo-'.$tenant->slug, (string) $request->header('X-Fake-Secret'));
        self::$events[] = 'authenticated:'.($authentic ? 'yes' : 'no');

        return $authentic;
    }

    protected function process(Request $request, Tenant $tenant): JsonResponse
    {
        self::$events[] = 'processed';

        Category::create(['name' => 'processado']);
        self::$visibleCategoriesByTenant[$tenant->id] = Category::where('name', 'processado')->count();

        return response()->json(['tenant' => $tenant->id]);
    }

    protected function rejectionStatus(): int
    {
        return self::$rejectionStatus;
    }
}

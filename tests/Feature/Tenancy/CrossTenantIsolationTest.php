<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\CentralRole;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Isolamento entre tenants com a arquitetura de tenancy por path:
 * - cardápio público em /{slug}
 * - painel do tenant em /painel/{slug} (tenancy nativa do Filament)
 * - painel central em /admin
 *
 * Cobre troca de slug na URL, IDOR de record e acesso cruzado painel/central.
 */
class CrossTenantIsolationTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    /** @var array{tenant: Tenant, admin: User, product: Product} */
    private array $a;

    /** @var array{tenant: Tenant, admin: User, product: Product} */
    private array $b;

    private User $central;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = $this->planWithAllFeatures();
        $this->a = $this->makeTenant('lazzopizza', $plan);
        $this->b = $this->makeTenant('emporiodapizza', $plan);

        $this->central = User::factory()->create([
            'tenant_id' => null,
            'central_role' => CentralRole::Platform,
        ]);

        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * @return array{tenant: Tenant, admin: User, product: Product}
     */
    private function makeTenant(string $slug, $plan): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $plan->id,
        ]);

        CurrentTenant::set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        app(SeedDefaultTenantRoles::class)($tenant);

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('Admin');

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => "Calabresa {$slug}",
            'price' => 40,
        ]);

        return ['tenant' => $tenant, 'admin' => $admin, 'product' => $product];
    }

    public function test_tenant_admin_reaches_own_panel(): void
    {
        $this->actingAs($this->a['admin'])
            ->get("/painel/{$this->a['tenant']->slug}")
            ->assertOk();
    }

    public function test_tenant_admin_cannot_reach_another_tenants_panel_by_swapping_the_slug(): void
    {
        // Filament aborta 404 (não 403) quando canAccessTenant() falha — de
        // propósito, para não confirmar a existência do outro tenant.
        $this->actingAs($this->a['admin'])
            ->get("/painel/{$this->b['tenant']->slug}")
            ->assertNotFound();
    }

    public function test_platform_super_admin_reaches_any_tenant_panel(): void
    {
        // RN-44 + cookie único: o super admin (central_role Plataforma) logado
        // em /admin acessa o painel de qualquer tenant sem novo login.
        $this->actingAs($this->central)
            ->get("/painel/{$this->a['tenant']->slug}")
            ->assertOk();

        $this->actingAs($this->central)
            ->get("/painel/{$this->b['tenant']->slug}")
            ->assertOk();
    }

    public function test_support_central_user_cannot_reach_a_tenant_panel(): void
    {
        $support = User::factory()->create([
            'tenant_id' => null,
            'central_role' => CentralRole::Support,
        ]);

        $this->actingAs($support)
            ->get("/painel/{$this->a['tenant']->slug}")
            ->assertForbidden();
    }

    public function test_central_user_reaches_the_central_panel(): void
    {
        $this->actingAs($this->central)->get('/admin')->assertOk();
    }

    public function test_tenant_user_cannot_reach_the_central_panel(): void
    {
        $this->actingAs($this->a['admin'])->get('/admin')->assertForbidden();
    }

    public function test_record_of_another_tenant_is_not_found_in_own_panel(): void
    {
        // Produto do tenant B pela URL do painel de A → 404 (fora do escopo
        // do getEloquentQuery). Nunca 403 — não vaza a existência do record.
        $this->actingAs($this->a['admin'])
            ->get("/painel/{$this->a['tenant']->slug}/products/{$this->b['product']->getKey()}/edit")
            ->assertNotFound();

        // Produto do próprio tenant → o record resolve (não é 404). O acesso
        // em si depende de permissão Shield, fora do escopo deste teste.
        $response = $this->actingAs($this->a['admin'])
            ->get("/painel/{$this->a['tenant']->slug}/products/{$this->a['product']->getKey()}/edit");

        $this->assertNotSame(404, $response->getStatusCode());
    }

    public function test_public_menu_only_shows_own_products(): void
    {
        $this->get("/{$this->a['tenant']->slug}")
            ->assertOk()
            ->assertSee('Calabresa lazzopizza')
            ->assertDontSee('Calabresa emporiodapizza');
    }

    public function test_global_scope_isolates_reads_between_tenants(): void
    {
        CurrentTenant::set($this->a['tenant']);

        $names = Product::query()->pluck('name');

        $this->assertTrue($names->contains('Calabresa lazzopizza'));
        $this->assertFalse($names->contains('Calabresa emporiodapizza'));
    }

    public function test_platform_super_admin_can_open_a_tenant_kitchen_ticket(): void
    {
        // OrderTicketController roda fora do Filament e checava
        // tenant_id === CurrentTenant::id() — o super admin (tenant_id null)
        // levava 403. Agora usa canOperateInCurrentTenant().
        $order = $this->makeOrder($this->a['tenant']);

        $this->actingAs($this->central)
            ->get("/{$this->a['tenant']->slug}/comanda/{$order->getKey()}")
            ->assertOk();

        // Usuário de outro tenant continua barrado.
        $this->actingAs($this->b['admin'])
            ->get("/{$this->a['tenant']->slug}/comanda/{$order->getKey()}")
            ->assertForbidden();
    }

    private function makeOrder(Tenant $tenant): Order
    {
        CurrentTenant::set($tenant);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'phone' => '11999990000',
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'order_number' => 1,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Preparing,
            'opened_at' => now(),
        ]);

        CurrentTenant::forget();

        return $order;
    }
}

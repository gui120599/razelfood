<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Livewire\Menu;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Resolução do tenant pelo primeiro segmento do path (`/{slug}/...`), feita
 * pelo middleware App\Http\Middleware\ResolveTenantFromPath no grupo de
 * rotas públicas de routes/web.php.
 */
class TenantPathResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => $status,
            'whatsapp_number' => '5511999999999',
        ]);
    }

    public function test_active_tenant_slug_serves_the_menu(): void
    {
        $this->tenant('lazzo');

        $this->get('/lazzo')->assertOk();
        $this->get('/lazzo/checkout')->assertOk();
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $this->get('/nao-existe')->assertNotFound();
    }

    public function test_suspended_tenant_is_unavailable(): void
    {
        $this->tenant('lazzo', TenantStatus::Suspended);

        $this->get('/lazzo')->assertStatus(503);
    }

    public function test_reserved_first_segments_never_resolve_as_tenant(): void
    {
        // Um tenant jamais deveria ter esses slugs (ValidTenantSlug barra),
        // mas mesmo que o banco tivesse, a rota /{tenant} não pode capturá-los.
        $this->get('/admin')->assertRedirect();          // painel central → login
        $this->get('/painel/login')->assertOk();          // login do painel do tenant
        $this->get('/up')->assertOk();                    // health-check
        $this->get('/livewire/livewire.js')->assertOk();  // asset do Livewire
    }

    public function test_whatsapp_link_uses_the_path_prefixed_tracking_url(): void
    {
        $tenant = $this->tenant('lazzo');
        $this->get('/lazzo'); // dispara ResolveTenantFromPath → URL::defaults(['tenant' => 'lazzo'])

        $this->assertStringContainsString('/lazzo/acompanhar/', route('order.tracking', ['order' => 'abc123']));
    }

    /**
     * Regressão: POST /livewire/update NÃO passa pelo grupo Route::prefix('{tenant}'),
     * então CurrentTenant fica null e route('checkout.index') estoura
     * UrlGenerationException. EstablishesTenantContext restaura o contexto.
     */
    public function test_livewire_update_restores_tenant_context_without_the_route_middleware(): void
    {
        $tenant = $this->tenant('lazzo');
        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);

        $component = Livewire::test(Menu::class);

        // Simula o /livewire/update real: a requisição não passou pelo middleware.
        CurrentTenant::forget();
        app()->forgetInstance(Tenant::class);

        $component->call('viewProduct', $product->id)->assertOk();

        $this->assertSame($tenant->id, CurrentTenant::id());
    }
}

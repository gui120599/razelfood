<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionLine;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Filtro de "linha de produção" (pista de pizza vs. pista de hambúrguer):
 * uma linha agrupa categorias, e a Central só mostra pedidos com ao menos
 * um item de alguma categoria da linha selecionada.
 */
class ProductionLineFilterTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

    private Category $pizzaCategory;

    private Category $burgerCategory;

    private Product $pizzaProduct;

    private Product $burgerProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $this->pizzaCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas']);
        $this->burgerCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Hambúrgueres']);

        $this->pizzaProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->pizzaCategory->id,
            'name' => 'Pizza Calabresa',
            'price' => 45,
        ]);

        $this->burgerProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->burgerCategory->id,
            'name' => 'X-Burguer',
            'price' => 30,
        ]);

        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');
        $this->actingAs($gerente);
    }

    private function makeOrderWithProduct(Product $product): Order
    {
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => $product->price,
            'grand_total' => $product->price,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
            'original_unit_price' => $product->price,
        ]);

        return $order;
    }

    public function test_filtering_by_production_line_only_shows_orders_with_items_from_its_categories(): void
    {
        $pizzaLine = ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Pizzas']);
        $pizzaLine->categories()->attach($this->pizzaCategory);

        $pizzaOrder = $this->makeOrderWithProduct($this->pizzaProduct);
        $burgerOrder = $this->makeOrderWithProduct($this->burgerProduct);

        $page = Livewire::test(Kitchen::class)->set('productionLineFilter', $pizzaLine->id)->instance();

        $ids = $page->ordersByStatus()->flatten()->pluck('id')->all();

        $this->assertContains($pizzaOrder->id, $ids);
        $this->assertNotContains($burgerOrder->id, $ids);
    }

    public function test_filtering_by_production_line_linked_to_a_parent_category_matches_products_in_child_categories(): void
    {
        $childCategory = Category::create([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $this->pizzaCategory->id,
            'name' => 'Pizzas Salgadas',
        ]);

        $childProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $childCategory->id,
            'name' => 'Pizza Calabresa Salgada',
            'price' => 50,
        ]);

        $pizzaLine = ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Pizzas']);
        $pizzaLine->categories()->attach($this->pizzaCategory);

        $childOrder = $this->makeOrderWithProduct($childProduct);
        $burgerOrder = $this->makeOrderWithProduct($this->burgerProduct);

        $page = Livewire::test(Kitchen::class)->set('productionLineFilter', $pizzaLine->id)->instance();

        $ids = $page->ordersByStatus()->flatten()->pluck('id')->all();

        $this->assertContains($childOrder->id, $ids);
        $this->assertNotContains($burgerOrder->id, $ids);
    }

    public function test_order_with_items_from_multiple_lines_appears_in_both(): void
    {
        $pizzaLine = ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Pizzas']);
        $pizzaLine->categories()->attach($this->pizzaCategory);

        $burgerLine = ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Hambúrgueres']);
        $burgerLine->categories()->attach($this->burgerCategory);

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 75,
            'grand_total' => 75,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->pizzaProduct->id,
            'quantity' => 1,
            'unit_price' => 45,
            'original_unit_price' => 45,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->burgerProduct->id,
            'quantity' => 1,
            'unit_price' => 30,
            'original_unit_price' => 30,
        ]);

        $onPizzaLine = Livewire::test(Kitchen::class)->set('productionLineFilter', $pizzaLine->id)->instance();
        $onBurgerLine = Livewire::test(Kitchen::class)->set('productionLineFilter', $burgerLine->id)->instance();

        $this->assertContains($order->id, $onPizzaLine->ordersByStatus()->flatten()->pluck('id')->all());
        $this->assertContains($order->id, $onBurgerLine->ordersByStatus()->flatten()->pluck('id')->all());
    }

    public function test_production_lines_are_scoped_by_tenant(): void
    {
        ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Pizzas']);

        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        CurrentTenant::set($otherTenant);
        ProductionLine::create(['tenant_id' => $otherTenant->id, 'name' => 'Pista do Outro Tenant']);
        CurrentTenant::set($this->tenant);

        $page = Livewire::test(Kitchen::class)->instance();

        $this->assertSame(['Pista de Pizzas'], $page->productionLines()->values()->all());
    }
}

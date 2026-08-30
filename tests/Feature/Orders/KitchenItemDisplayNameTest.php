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
 * Item de pedido combo (vários sabores) precisa mostrar todos os sabores no
 * card, não só o produto "âncora" gravado em product_id.
 */
class KitchenItemDisplayNameTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

    private Category $category;

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

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'allows_flavors' => true,
        ]);

        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');
        $this->actingAs($gerente);
    }

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'price' => 40,
        ]);
    }

    public function test_combo_item_shows_all_flavor_names_joined(): void
    {
        $calabresa = $this->makeProduct('Calabresa');
        $marguerita = $this->makeProduct('Marguerita');

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 45,
            'grand_total' => 45,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $calabresa->id,
            'quantity' => 1,
            'unit_price' => 45,
            'original_unit_price' => 45,
            'flavors' => [$calabresa->id, $marguerita->id],
        ]);

        $page = Livewire::test(Kitchen::class)->instance();

        $item = $page->ordersByStatus()->flatten()->firstWhere('id', $order->id)->items->first();

        $this->assertSame('Calabresa / Marguerita', $item->displayName);
    }

    public function test_combo_item_still_shows_flavor_name_after_flavor_product_is_soft_deleted(): void
    {
        $calabresa = $this->makeProduct('Calabresa');
        $marguerita = $this->makeProduct('Marguerita');

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 45,
            'grand_total' => 45,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $calabresa->id,
            'quantity' => 1,
            'unit_price' => 45,
            'original_unit_price' => 45,
            'flavors' => [$calabresa->id, $marguerita->id],
        ]);

        $marguerita->delete();

        $page = Livewire::test(Kitchen::class)->instance();

        $item = $page->ordersByStatus()->flatten()->firstWhere('id', $order->id)->items->first();

        $this->assertSame('Calabresa / Marguerita', $item->displayName);
    }

    public function test_simple_item_shows_the_product_name(): void
    {
        $product = $this->makeProduct('Coca-Cola 2L');

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 40,
            'original_unit_price' => 40,
        ]);

        $page = Livewire::test(Kitchen::class)->instance();

        $item = $page->ordersByStatus()->flatten()->firstWhere('id', $order->id)->items->first();

        $this->assertSame('Coca-Cola 2L', $item->displayName);
    }
}

<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\BuildOrderItemLines;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildOrderItemLinesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);

        $this->category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'tenant_id' => $this->tenant->id,
            'items_total' => 0, 'grand_total' => 0,
            'status' => OrderStatus::Preparing, 'opened_at' => now(),
        ]);
    }

    public function test_builds_combo_line_with_flavors_joined_and_category(): void
    {
        $order = $this->makeOrder();
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Marguerita', 'price' => 50]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $flavorA->id, 'quantity' => 2,
            'unit_price' => 45, 'original_unit_price' => 45,
            'flavors' => [$flavorA->id, $flavorB->id], 'addons_total' => 0,
        ]);

        $line = app(BuildOrderItemLines::class)($order->fresh('items'))->first();

        $this->assertSame('Calabresa / Marguerita', $line['name']);
        $this->assertSame('Pizzas', $line['category_name']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(90.0, $line['line_total']);
    }

    public function test_builds_addon_display_for_specific_flavor_and_whole_product(): void
    {
        $order = $this->makeOrder();
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Marguerita', 'price' => 50]);
        $bacon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        $borda = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Borda recheada', 'price' => 8]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $flavorA->id, 'quantity' => 1,
            'unit_price' => 45, 'original_unit_price' => 45,
            'flavors' => [$flavorA->id, $flavorB->id],
            'addons' => [
                ['addon_id' => $bacon->id, 'quantity' => 1, 'target' => $flavorA->id, 'target_share' => 0.5, 'unit_cost' => 3],
                ['addon_id' => $borda->id, 'quantity' => 1, 'target' => null, 'target_share' => 1.0, 'unit_cost' => 8],
            ],
            'addons_total' => 11,
        ]);

        $line = app(BuildOrderItemLines::class)($order->fresh('items'))->first();

        $this->assertSame(['1x Bacon extra (Calabresa)', '1x Borda recheada (produto inteiro)'], $line['addons_display']);
        $this->assertSame(56.0, $line['line_total']); // (45 + 11) * 1
    }

    public function test_falls_back_to_placeholder_when_product_is_removed(): void
    {
        $order = $this->makeOrder();
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Fugaz', 'price' => 30]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => 30, 'original_unit_price' => 30, 'addons_total' => 0,
        ]);

        $product->delete();

        $line = app(BuildOrderItemLines::class)($order->fresh('items'))->first();

        $this->assertSame('Produto removido', $line['name']);
    }

    public function test_builds_gift_display_for_accepted_and_declined_gifts(): void
    {
        $order = $this->makeOrder();
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Pizza Calabresa', 'price' => 65]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);
        $juice = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => 'Suco de Laranja', 'price' => 9]);

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => 65, 'original_unit_price' => 65, 'addons_total' => 0,
            'gifts' => [
                ['gift_product_id' => $soda->id, 'quantity' => 2, 'accepted' => true],
                ['gift_product_id' => $juice->id, 'quantity' => 1, 'accepted' => false],
            ],
        ]);

        $line = app(BuildOrderItemLines::class)($order->fresh('items'))->first();

        $this->assertSame([
            '🎁 2x Guaraná 1,5L',
            '🎁 Suco de Laranja — recusado pelo cliente',
        ], $line['gifts_display']);
    }
}

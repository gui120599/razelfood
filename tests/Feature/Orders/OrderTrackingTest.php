<?php

namespace Tests\Feature\Orders;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * RF-23/RN-28: página de acompanhamento do pedido, reskinada no mesmo
 * padrão visual escuro do checkout/cardápio.
 */
class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_page_shows_status_items_and_totals(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'delivery_fee' => 0,
            'grand_total' => 40,
            'status' => OrderStatus::Preparing,
            'opened_at' => now(),
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'payment_option_name' => 'Dinheiro',
            'is_cash' => true,
            'amount' => 40,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 40,
            'original_unit_price' => 40,
        ]);

        $this->get(route('order.tracking', ['order' => $order->public_token]))
            ->assertOk()
            ->assertSee('Acompanhar pedido')
            ->assertSee('Em Preparação')
            ->assertSee('Calabresa')
            ->assertSee('Retirada no local')
            ->assertSee('Dinheiro');
    }

    public function test_tracking_page_shows_combo_flavors_addons_category_and_order_notes(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
        ]);

        $flavorA = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $tenant->id, 'name' => 'Bacon extra', 'price' => 6]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 51,
            'delivery_fee' => 0,
            'grand_total' => 51,
            'status' => OrderStatus::Preparing,
            'opened_at' => now(),
            'notes' => 'Entregar na portaria',
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'payment_option_name' => 'Dinheiro',
            'is_cash' => true,
            'amount' => 51,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $flavorA->id,
            'quantity' => 1,
            'unit_price' => 45,
            'original_unit_price' => 45,
            'flavors' => [$flavorA->id, $flavorB->id],
            'note' => 'Bem passada',
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => $flavorA->id]],
            'addons_total' => 6,
        ]);

        $this->get(route('order.tracking', ['order' => $order->public_token]))
            ->assertOk()
            ->assertSee('Calabresa / Marguerita')
            ->assertSee('Pizzas')
            ->assertSee('1x Bacon extra (Calabresa)')
            ->assertSee('Bem passada')
            ->assertSee('Entregar na portaria');
    }

    public function test_tracking_page_shows_cancellation_reason_when_cancelled(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Cancelled,
            'cancellation_reason' => CancellationReason::CustomerGaveUp,
            'opened_at' => now(),
        ]);

        $this->get(route('order.tracking', ['order' => $order->public_token]))
            ->assertOk()
            ->assertSee('Cancelado');
    }

    /**
     * RNF-07/LGPD: a URL de acompanhamento usa o `public_token` opaco, nunca
     * o id sequencial — não pode ser enumerável (a página expõe nome,
     * endereço e telefone do cliente).
     */
    public function test_tracking_url_uses_opaque_token_and_rejects_sequential_id(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Preparing,
            'opened_at' => now(),
        ]);

        $this->assertNotEmpty($order->public_token);
        $this->assertStringContainsString($order->public_token, route('order.tracking', ['order' => $order->public_token]));

        $base = "/{$tenant->slug}";
        $this->get("{$base}/acompanhar/{$order->public_token}")->assertOk();
        $this->get("{$base}/acompanhar/{$order->id}")->assertNotFound();
        $this->get("{$base}/acompanhar/nao-existe")->assertNotFound();
    }
}

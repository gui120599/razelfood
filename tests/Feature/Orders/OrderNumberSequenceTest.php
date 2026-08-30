<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateOrderFromCart;
use App\Enums\OrderOrigin;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Número de pedido do estabelecimento: sequência contínua por tenant,
 * alocada sob lock dentro da transação de CreateOrderFromCart
 * (App\Actions\Orders\AllocateOrderNumber).
 */
class OrderNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant '.$slug,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);
    }

    private function placeOrder(Tenant $tenant): Order
    {
        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $payment = PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Dinheiro', 'is_cash' => true]);

        return app(CreateOrderFromCart::class)(
            [['type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $payment->id, 'amount' => 8.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );
    }

    public function test_order_numbers_are_sequential_per_tenant(): void
    {
        $tenant = $this->makeTenant('tenant-a');

        $numbers = collect(range(1, 3))->map(fn () => $this->placeOrder($tenant)->order_number);

        $this->assertSame([1, 2, 3], $numbers->all());
        $this->assertSame(3, $tenant->fresh()->orders_sequence);
    }

    public function test_order_numbers_are_isolated_between_tenants(): void
    {
        $tenantA = $this->makeTenant('tenant-a');
        $tenantB = $this->makeTenant('tenant-b');

        $this->placeOrder($tenantA);
        $this->placeOrder($tenantA);
        $orderB = $this->placeOrder($tenantB);

        $this->assertSame(1, $orderB->order_number);
        $this->assertSame(2, $tenantA->fresh()->orders_sequence);
        $this->assertSame(1, $tenantB->fresh()->orders_sequence);
    }

    public function test_allocation_continues_from_existing_sequence(): void
    {
        $tenant = $this->makeTenant('tenant-a');
        $tenant->update(['orders_sequence' => 41]);

        $this->assertSame(42, $this->placeOrder($tenant)->order_number);
    }
}

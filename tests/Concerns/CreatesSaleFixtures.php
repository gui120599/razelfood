<?php

namespace Tests\Concerns;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixtures da Fase 1.0 (venda): tenant, formas de pagamento, produtos e pedidos
 * com itens. O projeto não usa factories de domínio — `Model::create` + helpers.
 */
trait CreatesSaleFixtures
{
    protected function makeSaleTenant(string $slug, bool $withPdv = true): Tenant
    {
        $plan = $withPdv ? $this->planWithAllFeaturesAndPdv() : $this->planWithAllFeatures();

        return Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $plan->id,
        ]);
    }

    protected function actAsTenant(Tenant $tenant): void
    {
        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    }

    protected function forgetTenantContext(): void
    {
        CurrentTenant::forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    protected function makePaymentOption(Tenant $tenant, string $name, bool $isCash = false): PaymentOption
    {
        return PaymentOption::create(['tenant_id' => $tenant->id, 'name' => $name, 'is_cash' => $isCash, 'show_in_menu' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProduct(Tenant $tenant, string $name = 'Calabresa', float $price = 40, array $attributes = []): Product
    {
        $category = Category::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Pizzas'],
        );

        return Product::create(array_merge([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => $price,
        ], $attributes));
    }

    /**
     * Pedido com 1 item cujo total é `$total` (sem taxa de entrega nem desconto).
     */
    protected function makeSaleOrder(Tenant $tenant, float $total = 40, OrderStatus $status = OrderStatus::Delivered): Order
    {
        $order = Order::create([
            'tenant_id' => $tenant->id,
            'items_total' => $total,
            'grand_total' => $total,
            'status' => $status,
            'origin' => OrderOrigin::Staff,
            'opened_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct($tenant, 'Item do pedido', $total)->id,
            'quantity' => 1,
            'unit_price' => $total,
            'original_unit_price' => $total,
        ]);

        return $order;
    }
}

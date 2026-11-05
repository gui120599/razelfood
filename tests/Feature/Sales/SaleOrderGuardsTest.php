<?php

namespace Tests\Feature\Sales;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\UpdateOrderFromCart;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\FinalizeSale;
use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Exceptions\CheckoutException;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * RN-PED-07: pedido já cobrado numa venda não pode ser cancelado nem editado
 * fora dela — a venda tem de ser cancelada antes.
 */
class SaleOrderGuardsTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        $this->cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    public function test_a_sold_order_cannot_be_cancelled_until_the_sale_is_cancelled(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Preparing);
        $sale = app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 40]]);

        try {
            app(CancelOrder::class)($order->fresh(), CancellationReason::CustomerGaveUp, null);
            $this->fail('Pedido vendido não pode ser cancelado.');
        } catch (OrderTransitionException $exception) {
            $this->assertStringContainsString("venda #{$sale->number}", $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        app(CancelSale::class)($sale, 'Cliente desistiu');
        app(CancelOrder::class)($order->fresh(), CancellationReason::CustomerGaveUp, null);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_sold_order_cannot_be_edited(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 40]]);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('já foi cobrado na venda');

        app(UpdateOrderFromCart::class)($order->fresh(), [], []);
    }

    public function test_orders_awaiting_sale_scope_excludes_sold_and_cancelled_orders(): void
    {
        $open = $this->makeSaleOrder($this->tenant, 10, OrderStatus::Preparing);
        $sold = $this->makeSaleOrder($this->tenant, 20);
        $this->makeSaleOrder($this->tenant, 30, OrderStatus::Cancelled);
        app(FinalizeSale::class)([$sold->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 20]]);

        $this->assertSame([$open->id], Order::awaitingSale()->pluck('id')->all());
    }

    public function test_cancel_order_rechecks_the_sale_under_lock_even_with_a_stale_model(): void
    {
        $stale = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Preparing); // model carregado ANTES da venda
        app(FinalizeSale::class)([$stale->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 40]]);

        $this->assertNull($stale->sale_id, 'O model em mãos está defasado: não sabe da venda.');

        try {
            app(CancelOrder::class)($stale, CancellationReason::CustomerGaveUp, null);
            $this->fail('A guarda precisa reavaliar sobre a linha travada.');
        } catch (OrderTransitionException $exception) {
            $this->assertStringContainsString('já foi cobrado', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Preparing, $stale->fresh()->status);
    }

    public function test_update_order_rechecks_the_sale_under_lock_even_with_a_stale_model(): void
    {
        $stale = $this->makeSaleOrder($this->tenant, 40);
        $itemsBefore = $stale->items()->count();
        app(FinalizeSale::class)([$stale->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 40]]);

        $cartItems = [['type' => 'simple', 'product_id' => $stale->items()->first()->product_id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null]];
        $checkout = ['payments' => [['payment_option_id' => $this->cash->id, 'amount' => 40.0, 'change_for' => null]]];

        try {
            app(UpdateOrderFromCart::class)($stale, $cartItems, $checkout);
            $this->fail('Pedido vendido não pode ser reescrito.');
        } catch (CheckoutException $exception) {
            $this->assertStringContainsString('já foi cobrado', $exception->getMessage());
        }

        $this->assertSame($itemsBefore, $stale->items()->count());
        $this->assertNotNull(OrderItem::where('order_id', $stale->id)->value('sale_id'), 'Os itens continuam vinculados à venda.');
    }
}

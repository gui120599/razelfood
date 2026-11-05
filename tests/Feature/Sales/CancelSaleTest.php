<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\FinalizeSale;
use App\Enums\OrderPaymentStatus;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sales\SaleCancellationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * RN-VEN-05/06: cancelar a venda devolve os pedidos a "a receber", devolve o
 * estoque só dos itens avulsos e respeita o gancho fiscal.
 */
class CancelSaleTest extends TestCase
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

    private function sell(Order $order, array $standalone = [], float $paid = 40): Sale
    {
        return app(FinalizeSale::class)(
            [$order->id],
            $standalone,
            [['payment_option_id' => $this->cash->id, 'amount' => $paid]],
        );
    }

    public function test_cancelling_returns_orders_and_items_to_receivable_and_keeps_payments_as_history(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $sale = $this->sell($order);
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $cancelled = app(CancelSale::class)($sale, '  Cliente desistiu  ', $manager);

        $this->assertSame(SaleStatus::Cancelled, $cancelled->status);
        $this->assertSame('Cliente desistiu', $cancelled->cancellation_note);
        $this->assertSame($manager->id, $cancelled->cancelled_by_user_id);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($order->fresh()->sale_id);
        $this->assertNull(OrderItem::where('order_id', $order->id)->value('sale_id'));
        $this->assertSame(OrderPaymentStatus::Pending, $order->fresh()->paymentStatus());
        $this->assertSame(1, $cancelled->payments->count(), 'Pagamentos ficam como histórico.');
        $this->assertSame(1, Order::awaitingSale()->count());
    }

    public function test_a_cancelled_sales_order_can_be_sold_again_with_a_new_number(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        app(CancelSale::class)($this->sell($order), 'Erro de digitação');

        $second = $this->sell($order);

        $this->assertSame(2, $second->number);
        $this->assertSame($second->id, $order->fresh()->sale_id);
    }

    public function test_cancelling_returns_the_stock_of_standalone_items_only(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $product = $this->makeProduct($this->tenant, 'Refrigerante', 8, ['controls_stock' => true, 'stock_quantity' => 10]);
        $sale = $this->sell($order, [['product_id' => $product->id, 'quantity' => 4]], 72);

        $this->assertSame('6.00', $product->fresh()->stock_quantity);
        $this->assertSame('4.00', $product->fresh()->sales_count);

        app(CancelSale::class)($sale, 'Troca de forma de pagamento');

        $this->assertSame('10.00', $product->fresh()->stock_quantity);
        $this->assertSame('0.00', $product->fresh()->sales_count);
    }

    public function test_cancelling_twice_or_without_a_note_is_rejected(): void
    {
        $sale = $this->sell($this->makeSaleOrder($this->tenant, 40));

        try {
            app(CancelSale::class)($sale, '   ');
            $this->fail('Motivo é obrigatório.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('motivo', $exception->getMessage());
        }

        app(CancelSale::class)($sale, 'ok');

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('já está cancelada');

        app(CancelSale::class)($sale->fresh(), 'de novo');
    }

    public function test_a_too_long_note_is_rejected(): void
    {
        $sale = $this->sell($this->makeSaleOrder($this->tenant, 40));

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('255 caracteres');

        app(CancelSale::class)($sale, str_repeat('a', 256));
    }

    public function test_the_cancellation_hook_can_block_and_nothing_changes(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $sale = $this->sell($order);

        $this->app->bind(SaleCancellationGuard::class, fn () => new class extends SaleCancellationGuard
        {
            public function blockedReason(Sale $sale): ?string
            {
                return 'Cancele a NFC-e antes de cancelar a venda.';
            }
        });

        try {
            app(CancelSale::class)($sale, 'Quero cancelar');
            $this->fail('O gancho fiscal deveria bloquear.');
        } catch (SaleException $exception) {
            $this->assertSame('Cancele a NFC-e antes de cancelar a venda.', $exception->getMessage());
        }

        $this->assertSame(SaleStatus::Finalized, $sale->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
    }

    public function test_default_hook_never_blocks(): void
    {
        $sale = $this->sell($this->makeSaleOrder($this->tenant, 40));

        $this->assertNull(app(SaleCancellationGuard::class)->blockedReason($sale));
    }
}

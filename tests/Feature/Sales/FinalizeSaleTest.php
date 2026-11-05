<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\FinalizeSale;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\FlashPromotion;
use App\Models\FlashPromotionProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sales\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * RN-VEN-01..03: fechamento de conta. Pagamento misto bate centavo a centavo,
 * troco só em dinheiro e o mesmo item nunca entra em duas vendas.
 */
class FinalizeSaleTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    private PaymentOption $card;

    private PaymentOption $pix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);

        $this->cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $this->card = $this->makePaymentOption($this->tenant, 'Cartão');
        $this->pix = $this->makePaymentOption($this->tenant, 'Pix');
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function finalize(array $orderIds, array $payments, array $standalone = [], ?User $by = null): Sale
    {
        return app(FinalizeSale::class)($orderIds, $standalone, $payments, $by);
    }

    private function pay(PaymentOption $option, float|string $amount, array $extra = []): array
    {
        return ['payment_option_id' => $option->id, 'amount' => $amount] + $extra;
    }

    /**
     * @return array{int, int} [líquido recebido em centavos, troco em centavos]
     */
    private function netAndChangeCents(Sale $sale): array
    {
        $net = 0;
        $change = 0;

        foreach (SalePayment::where('sale_id', $sale->id)->get() as $payment) {
            $net += Money::toCents($payment->amount) - Money::toCents($payment->change_amount);
            $change += Money::toCents($payment->change_amount);
        }

        return [$net, $change];
    }

    public function test_finalizes_a_single_order_and_links_order_and_items(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $sale = $this->finalize([$order->id], [$this->pay($this->cash, '40.00')], by: $user);

        $this->assertSame(SaleStatus::Finalized, $sale->status);
        $this->assertSame(1, $sale->number);
        $this->assertSame('40.00', $sale->total);
        $this->assertSame('40.00', $sale->items_total);
        $this->assertSame($user->id, $sale->finalized_by_user_id);
        $this->assertNotNull($sale->finalized_at);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame($sale->id, OrderItem::where('order_id', $order->id)->value('sale_id'));
        $this->assertSame($this->tenant->id, $sale->tenant_id);
        $this->assertSame($this->tenant->id, $sale->payments->first()->tenant_id);
        $this->assertSame('Dinheiro', $sale->payments->first()->payment_option_name);
        $this->assertTrue($sale->payments->first()->is_cash);
    }

    public function test_order_status_is_not_changed_by_the_sale_and_payment_status_is_derived(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Preparing);
        $this->assertSame(OrderPaymentStatus::Pending, $order->paymentStatus());

        $this->finalize([$order->id], [$this->pay($this->pix, '40.00')]);

        $order->refresh();
        $this->assertSame(OrderStatus::Preparing, $order->status);
        $this->assertNull($order->finished_at);
        $this->assertSame(OrderPaymentStatus::Paid, $order->paymentStatus());
    }

    public function test_sale_numbers_are_sequential_per_tenant(): void
    {
        $first = $this->finalize([$this->makeSaleOrder($this->tenant)->id], [$this->pay($this->pix, '40.00')]);
        $second = $this->finalize([$this->makeSaleOrder($this->tenant)->id], [$this->pay($this->pix, '40.00')]);

        $this->assertSame([1, 2], [$first->number, $second->number]);
        $this->assertSame(2, (int) DB::table('tenants')->where('id', $this->tenant->id)->value('sales_sequence'));
    }

    public function test_one_sale_can_charge_several_orders_with_snapshot_totals(): void
    {
        $a = $this->makeSaleOrder($this->tenant, 60);
        $b = Order::create([
            'tenant_id' => $this->tenant->id, 'items_total' => 40, 'discount_total' => 5, 'delivery_fee' => 8,
            'grand_total' => 48, 'status' => OrderStatus::Ready, 'origin' => 'staff', 'opened_at' => now(),
        ]);

        $sale = $this->finalize([$a->id, $b->id], [$this->pay($this->card, '108.00')]);

        $this->assertSame('108.00', $sale->total);
        $this->assertSame('100.00', $sale->items_total);
        $this->assertSame('5.00', $sale->discount_total);
        $this->assertSame('8.00', $sale->delivery_fee);
        $this->assertSame(2, Order::where('sale_id', $sale->id)->count());
    }

    public function test_mixed_payment_net_minus_change_equals_total_to_the_cent(): void
    {
        $sale = $this->finalize(
            [$this->makeSaleOrder($this->tenant, 60)->id, $this->makeSaleOrder($this->tenant, 40.50)->id],
            [$this->pay($this->card, '70.25'), $this->pay($this->pix, '20.00'), $this->pay($this->cash, '20.25')],
        );

        [$net, $change] = $this->netAndChangeCents($sale);

        $this->assertSame(10050, Money::toCents($sale->total));
        $this->assertSame(10050, $net);
        $this->assertSame(1000, $change);
    }

    public function test_cent_precision_with_repeating_decimals(): void
    {
        $sale = $this->finalize(
            [$this->makeSaleOrder($this->tenant, 33.33)->id, $this->makeSaleOrder($this->tenant, 33.33)->id],
            [$this->pay($this->card, '22.22'), $this->pay($this->pix, '22.22'), $this->pay($this->cash, '22.22')],
        );

        $this->assertSame([6666, 0], $this->netAndChangeCents($sale));
    }

    public function test_change_is_computed_on_the_server_and_comes_out_of_the_cash_line(): void
    {
        $sale = $this->finalize(
            [$this->makeSaleOrder($this->tenant, 40)->id],
            [$this->pay($this->card, '20.00'), $this->pay($this->cash, '30.00')],
        );

        $payments = $sale->payments->sortBy('id')->values();

        $this->assertSame('0.00', $payments[0]->change_amount);
        $this->assertSame('10.00', $payments[1]->change_amount);
        $this->assertSame('30.00', $payments[1]->amount);
        $this->assertSame([4000, 1000], $this->netAndChangeCents($sale));
    }

    public function test_change_spills_from_the_last_cash_line_to_the_previous_one(): void
    {
        $sale = $this->finalize(
            [$this->makeSaleOrder($this->tenant, 40)->id],
            [$this->pay($this->cash, '50.00'), $this->pay($this->cash, '10.00')],
        );

        $payments = $sale->payments->sortBy('id')->values();

        // troco 20: a última linha (10) devolve tudo e o resto sai da anterior
        $this->assertSame('10.00', $payments[0]->change_amount);
        $this->assertSame('10.00', $payments[1]->change_amount);
        $this->assertSame([4000, 2000], $this->netAndChangeCents($sale));
    }

    public function test_optional_card_fields_are_trimmed_and_blank_becomes_null(): void
    {
        $sale = $this->finalize(
            [$this->makeSaleOrder($this->tenant, 40)->id],
            [$this->pay($this->card, '40.00', ['card_brand' => '  Visa  ', 'authorization_code' => '   '])],
        );

        $this->assertSame('Visa', $sale->payments->first()->card_brand);
        $this->assertNull($sale->payments->first()->authorization_code);
        $this->assertNull($sale->payments->first()->payment_terminal_id);
    }

    public function test_insufficient_payment_is_rejected_and_nothing_is_persisted(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);

        try {
            $this->finalize([$order->id], [$this->pay($this->cash, '39.99')]);
            $this->fail('Deveria recusar pagamento insuficiente.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('faltam R$ 0,01', $exception->getMessage());
        }

        $this->assertSame(0, Sale::count());
        $this->assertNull($order->fresh()->sale_id);
        $this->assertSame(0, (int) DB::table('tenants')->where('id', $this->tenant->id)->value('sales_sequence'), 'O número da venda também é revertido.');
    }

    public function test_overpaying_with_a_non_cash_method_is_rejected(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('troco só pode sair de pagamento em dinheiro');

        $this->finalize([$order->id], [$this->pay($this->card, '40.01')]);
    }

    public function test_change_bigger_than_the_cash_received_is_rejected(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);

        $this->expectException(SaleException::class);

        // recebido 70 (card 60 + cash 10) → troco 30, mas só há 10 em dinheiro
        $this->finalize([$order->id], [$this->pay($this->card, '60.00'), $this->pay($this->cash, '10.00')]);
    }

    public function test_invalid_payment_inputs_are_rejected(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);

        foreach ([
            [[], 'Informe ao menos uma forma de pagamento'],
            [[['payment_option_id' => 999999, 'amount' => '40.00']], 'Forma de pagamento inválida'],
            [[$this->pay($this->cash, '0')], 'maior que zero'],
            [[$this->pay($this->cash, '-5')], 'maior que zero'],
        ] as [$payments, $message]) {
            try {
                $this->finalize([$order->id], $payments);
                $this->fail("Deveria recusar: {$message}");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_a_zero_total_order_can_be_closed_without_payments(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 0);

        $sale = $this->finalize([$order->id], []);

        $this->assertSame('0.00', $sale->total);
        $this->assertSame(0, $sale->payments->count());
    }

    public function test_requires_at_least_one_order_or_item(): void
    {
        $this->expectException(SaleException::class);

        $this->finalize([], [$this->pay($this->cash, '10.00')]);
    }

    public function test_cancelled_and_unknown_orders_are_rejected(): void
    {
        $cancelled = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Cancelled);

        foreach ([[$cancelled->id], [999999]] as $ids) {
            try {
                $this->finalize($ids, [$this->pay($this->cash, '40.00')]);
                $this->fail('Deveria recusar.');
            } catch (SaleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_an_order_cannot_enter_two_sales(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $first = $this->finalize([$order->id], [$this->pay($this->cash, '40.00')]);

        try {
            $this->finalize([$order->id], [$this->pay($this->cash, '40.00')]);
            $this->fail('O pedido já vendido não pode entrar em outra venda.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('já foi cobrado', $exception->getMessage());
        }

        $this->assertSame(1, Sale::count());
        $this->assertSame($first->id, $order->fresh()->sale_id);
    }

    public function test_an_item_already_sold_blocks_the_order_even_if_the_order_link_is_missing(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $other = $this->finalize([$this->makeSaleOrder($this->tenant, 10)->id], [$this->pay($this->cash, '10.00')]);
        OrderItem::where('order_id', $order->id)->update(['sale_id' => $other->id]); // inconsistência forçada

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('item dos pedidos selecionados já foi cobrado');

        $this->finalize([$order->id], [$this->pay($this->cash, '40.00')]);
    }

    public function test_by_design_orders_are_locked_but_order_items_are_not_range_locked(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->finalize([$order->id], [$this->pay($this->cash, '40.00')]);

        $locked = fn (string $table): bool => collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'for update') && str_contains($sql, "from `{$table}`")
        );

        $this->assertTrue($locked('orders'), 'Pedidos precisam ser travados (lockForUpdate) — é o que serializa a venda.');
        $this->assertTrue($locked('tenants'), 'A sequência do número da venda precisa de lock.');
        $this->assertFalse($locked('order_items'), 'order_items não tem tenant_id: um lock por order_id travaria o gap do índice de todos os tenants.');
    }

    public function test_standalone_soft_deleted_product_is_not_sellable(): void
    {
        $product = $this->makeProduct($this->tenant, 'Descontinuado', 5);
        $product->delete();

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('não foram encontrados');

        $this->finalize([], [$this->pay($this->cash, '5.00')], [['product_id' => $product->id, 'quantity' => 1]]);
    }

    public function test_absurdly_large_payment_is_rejected_instead_of_overflowing_the_column(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('grande demais');

        $this->finalize([$order->id], [$this->pay($this->cash, '100000000.00')]);
    }

    public function test_standalone_price_uses_the_product_promotion_even_when_a_flash_promotion_matches(): void
    {
        $product = $this->makeProduct($this->tenant, 'Pizza', 50, ['promotional_price' => 42]);
        $flash = FlashPromotion::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Relâmpago', 'is_active' => true,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
        ]);
        FlashPromotionProduct::create(['tenant_id' => $this->tenant->id, 'flash_promotion_id' => $flash->id, 'product_id' => $product->id, 'promotional_price' => 30, 'total_quantity' => 10, 'sold_quantity' => 0]);

        $sale = $this->finalize([], [$this->pay($this->cash, '42.00')], [['product_id' => $product->id, 'quantity' => 1]]);

        $this->assertSame('42.00', $sale->items->first()->unit_price);
        $this->assertSame(0, (int) DB::table('flash_promotion_products')->where('product_id', $product->id)->value('sold_quantity'), 'Avulso não consome saldo de promoção relâmpago.');
    }

    public function test_standalone_item_debits_stock_and_counts_the_sale(): void
    {
        $product = $this->makeProduct($this->tenant, 'Refrigerante', 8, ['controls_stock' => true, 'stock_quantity' => 10]);

        $sale = $this->finalize([], [$this->pay($this->pix, '24.00')], [['product_id' => $product->id, 'quantity' => 3]]);

        $this->assertSame('24.00', $sale->total);
        $this->assertSame('24.00', $sale->items_total);
        $this->assertCount(1, $sale->items);
        $this->assertSame('Refrigerante', $sale->items->first()->product_name);
        $this->assertSame(3, $sale->items->first()->quantity);
        $this->assertSame('8.00', $sale->items->first()->unit_price);
        $this->assertSame('7.00', $product->fresh()->stock_quantity);
        $this->assertSame('3.00', $product->fresh()->sales_count);
    }

    public function test_standalone_items_of_the_same_product_are_merged_and_checked_together(): void
    {
        $product = $this->makeProduct($this->tenant, 'Suco', 6, ['controls_stock' => true, 'stock_quantity' => 3]);

        try {
            $this->finalize([], [$this->pay($this->pix, '24.00')], [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 2],
            ]);
            $this->fail('2 + 2 passa do estoque de 3.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('não tem estoque suficiente', $exception->getMessage());
        }

        $this->assertSame('3.00', $product->fresh()->stock_quantity);
        $this->assertSame(0, Sale::count());
    }

    public function test_standalone_product_without_stock_control_never_blocks(): void
    {
        $product = $this->makeProduct($this->tenant, 'Água', 5);

        $sale = $this->finalize([], [$this->pay($this->cash, '50.00')], [['product_id' => $product->id, 'quantity' => 10]]);

        $this->assertSame('50.00', $sale->total);
        $this->assertSame('10.00', $product->fresh()->sales_count);
    }

    public function test_standalone_item_validations(): void
    {
        $product = $this->makeProduct($this->tenant, 'Água', 5);

        foreach ([
            [[['product_id' => $product->id, 'quantity' => 0]], 'pelo menos 1'],
            [[['product_id' => 999999, 'quantity' => 1]], 'não foram encontrados'],
        ] as [$items, $message]) {
            try {
                $this->finalize([], [$this->pay($this->cash, '5.00')], $items);
                $this->fail("Deveria recusar: {$message}");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_orders_and_standalone_items_in_the_same_sale(): void
    {
        $order = $this->makeSaleOrder($this->tenant, 40);
        $product = $this->makeProduct($this->tenant, 'Refrigerante', 8);

        $sale = $this->finalize([$order->id], [$this->pay($this->card, '56.00')], [['product_id' => $product->id, 'quantity' => 2]]);

        $this->assertSame('56.00', $sale->total);
        $this->assertSame('56.00', $sale->items_total);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame(1, $sale->items->count());
        $this->assertSame(1, Product::where('name', 'Refrigerante')->count());
    }
}

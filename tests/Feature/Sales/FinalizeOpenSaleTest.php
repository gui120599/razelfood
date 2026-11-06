<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\ApplySaleDiscountPercent;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\SetSaleDeliveryFee;
use App\Actions\Sales\StartSale;
use App\Enums\OrderPaymentStatus;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * D-08: finalizar o rascunho. É aqui que a venda ganha número, baixa o estoque, valida o
 * pagamento (troco no servidor, centavo a centavo) e vincula os pedidos cobrados por inteiro.
 */
class FinalizeOpenSaleTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    private PaymentOption $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        $this->cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $this->card = $this->makePaymentOption($this->tenant, 'Cartão');
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function pay(Sale $sale, PaymentOption $option, float|string $amount): void
    {
        app(AddSalePayment::class)($sale, ['payment_option_id' => $option->id, 'amount' => $amount]);
    }

    private function draftWithPizza(float $price = 40): Sale
    {
        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza '.uniqid(), $price)->id);

        return $sale->fresh();
    }

    public function test_finalizing_allocates_the_number_and_computes_the_change_on_the_server(): void
    {
        $sale = $this->draftWithPizza(40);
        $this->pay($sale, $this->cash, '50.00');
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $finalized = app(FinalizeOpenSale::class)($sale, $user);

        $this->assertSame(SaleStatus::Finalized, $finalized->status);
        $this->assertSame(1, $finalized->number);
        $this->assertSame($user->id, $finalized->finalized_by_user_id);
        $this->assertNotNull($finalized->finalized_at);
        $payment = SalePayment::where('sale_id', $sale->id)->sole();
        $this->assertSame('50.00', $payment->amount);
        $this->assertSame('10.00', $payment->change_amount);
        $this->assertSame('40.00', $payment->netAmount());
    }

    public function test_drafts_do_not_consume_numbers_and_finalized_sales_stay_sequential(): void
    {
        $this->draftWithPizza();
        $this->draftWithPizza();
        $first = $this->draftWithPizza();
        $second = $this->draftWithPizza();
        $this->pay($first, $this->cash, 40);
        $this->pay($second, $this->cash, 40);

        $this->assertSame(0, (int) $this->tenant->fresh()->sales_sequence);
        $this->assertSame(1, app(FinalizeOpenSale::class)($first)->number);
        $this->assertSame(2, app(FinalizeOpenSale::class)($second)->number);
    }

    public function test_an_order_and_standalone_items_pay_together_with_a_mixed_payment(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeProduct($this->tenant, 'Pizza', 40)]], deliveryFee: 5);
        $sale = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Refri', 8)->id);
        $this->pay($sale, $this->card, '20.00');
        $this->pay($sale, $this->cash, '40.00');

        $finalized = app(FinalizeOpenSale::class)($sale);

        $this->assertSame('53.00', $finalized->total);
        $this->assertSame('5.00', $finalized->delivery_fee);
        $this->assertSame(['Cartão', 'Dinheiro'], $finalized->payments->pluck('payment_option_name')->all());
        $this->assertSame('7.00', $finalized->payments->firstWhere('payment_option_name', 'Dinheiro')->change_amount);
        $this->assertSame($finalized->id, $order->fresh()->sale_id);
        $this->assertSame($finalized->id, OrderItem::where('order_id', $order->id)->value('sale_id'));
        $this->assertSame('45.00', $finalized->saleOrders->sole()->order_total);
    }

    public function test_the_manual_discount_and_the_freight_close_the_total(): void
    {
        $sale = $this->draftWithPizza(100);
        app(ApplySaleDiscountPercent::class)($sale, 10);
        app(SetSaleDeliveryFee::class)($sale, '4.00');
        $this->pay($sale, $this->card, '94.00');

        $finalized = app(FinalizeOpenSale::class)($sale);

        $this->assertSame('100.00', $finalized->items_total);
        $this->assertSame('10.00', $finalized->manual_discount_total);
        $this->assertSame('94.00', $finalized->total);
    }

    public function test_insufficient_payment_keeps_the_draft_open_without_a_number(): void
    {
        $sale = $this->draftWithPizza(40);
        $this->pay($sale, $this->card, '30.00');

        try {
            app(FinalizeOpenSale::class)($sale);
            $this->fail('Pagamento insuficiente foi aceito.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('Pagamento insuficiente', $exception->getMessage());
        }

        $sale->refresh();
        $this->assertSame(SaleStatus::Open, $sale->status);
        $this->assertNull($sale->number);
        $this->assertSame(0, (int) $this->tenant->fresh()->sales_sequence);
        $this->assertSame('0.00', SalePayment::where('sale_id', $sale->id)->sole()->change_amount);
    }

    public function test_change_only_comes_from_cash(): void
    {
        $sale = $this->draftWithPizza(40);
        $this->pay($sale, $this->card, '50.00');

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('troco só pode sair de pagamento em dinheiro');
        app(FinalizeOpenSale::class)($sale);
    }

    public function test_an_empty_draft_cannot_be_finalized(): void
    {
        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('Lance ao menos um pedido ou um item');
        app(FinalizeOpenSale::class)(app(StartSale::class)());
    }

    public function test_finalizing_twice_fails_the_second_time(): void
    {
        $sale = $this->draftWithPizza(40);
        $this->pay($sale, $this->cash, 40);
        app(FinalizeOpenSale::class)($sale);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('não está mais aberta');
        app(FinalizeOpenSale::class)($sale);
    }

    public function test_stock_is_decremented_only_when_finalizing_and_checked_again_then(): void
    {
        $product = $this->makeProduct($this->tenant, 'Vinho', 40, ['controls_stock' => true, 'stock_quantity' => 5]);
        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $product->id, 2);
        $this->pay($sale, $this->cash, 80);

        $this->assertSame('5.00', (string) Product::find($product->id)->stock_quantity);
        app(FinalizeOpenSale::class)($sale);
        $this->assertSame('3.00', (string) Product::find($product->id)->stock_quantity);

        // Outro operador zera o estoque depois de o item entrar no rascunho: a baixa revalida.
        $late = app(StartSale::class)();
        app(AddStandaloneItem::class)($late, $product->id, 3);
        $this->pay($late, $this->cash, 120);
        Product::whereKey($product->id)->update(['stock_quantity' => 1]);

        try {
            app(FinalizeOpenSale::class)($late);
            $this->fail('Estoque insuficiente foi aceito.');
        } catch (SaleException) {
            $this->assertSame(SaleStatus::Open, $late->fresh()->status);
        }
    }

    public function test_cancelling_a_finalized_partial_sale_releases_only_its_items_and_returns_stock(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [
            ['product' => $this->makeProduct($this->tenant, 'Pizza', 40)],
            ['product' => $this->makeProduct($this->tenant, 'Refri', 8)],
        ]);
        [$pizza, $soda] = OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('id')->all();
        $product = $this->makeProduct($this->tenant, 'Vinho', 30, ['controls_stock' => true, 'stock_quantity' => 4]);

        $first = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id, [$pizza]);
        app(AddStandaloneItem::class)($first, $product->id);
        $this->pay($first, $this->cash, 70);
        app(FinalizeOpenSale::class)($first);
        $second = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id, [$soda]);
        $this->pay($second, $this->cash, 8);
        app(FinalizeOpenSale::class)($second);

        $this->assertSame('3.00', (string) Product::find($product->id)->stock_quantity);

        app(CancelSale::class)($first, 'Erro de lançamento');

        $this->assertNull(OrderItem::find($pizza)->sale_id);
        $this->assertSame($second->id, OrderItem::find($soda)->sale_id, 'A outra venda não é tocada.');
        $this->assertSame('4.00', (string) Product::find($product->id)->stock_quantity);
        $this->assertSame(OrderPaymentStatus::PartiallyPaid, Order::find($order->id)->paymentStatus());
    }

    public function test_a_draft_of_another_tenant_is_never_found(): void
    {
        $sale = $this->draftWithPizza(40);
        $this->pay($sale, $this->cash, 40);

        $other = $this->makeSaleTenant('emporiodapizza');
        $this->actAsTenant($other);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('Venda não encontrada');
        app(FinalizeOpenSale::class)($sale);
    }
}

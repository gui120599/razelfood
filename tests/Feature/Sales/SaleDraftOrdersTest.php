<?php

namespace Tests\Feature\Sales;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\UpdateOrderFromCart;
use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\DetachOrderItems;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\FinalizeSale;
use App\Actions\Sales\SetSaleClient;
use App\Actions\Sales\StartSale;
use App\Enums\CancellationReason;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Exceptions\CheckoutException;
use App\Exceptions\OrderTransitionException;
use App\Exceptions\SaleException;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SaleOrder;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * D-08 item a item: lançar itens de pedido num rascunho os RESERVA à venda. Reserva bloqueia
 * edição/cancelamento do pedido, impede o mesmo item em duas vendas e some ao devolver o item
 * ou cancelar a venda. O frete do pedido é cobrado uma vez só.
 */
class SaleDraftOrdersTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
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

    /**
     * Pedido com 2 itens: pizza 40 e refri 8 (× 2 = 16), frete opcional.
     */
    private function order(float $fee = 0, ?Client $client = null): Order
    {
        return $this->makeNfceOrder($this->tenant, [
            ['product' => $this->makeProduct($this->tenant, 'Pizza '.uniqid(), 40)],
            ['product' => $this->makeProduct($this->tenant, 'Refri '.uniqid(), 8), 'quantity' => 2],
        ], $fee, $client);
    }

    /**
     * @return list<int>
     */
    private function itemIds(Order $order): array
    {
        return OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('id')->all();
    }

    private function newSale(): Sale
    {
        return app(StartSale::class)();
    }

    public function test_attaching_an_order_reserves_all_its_free_items_and_totals_the_sale(): void
    {
        $order = $this->order();
        $sale = $this->newSale();

        $sale = app(AttachOrderItems::class)($sale, $order->id);

        $this->assertSame(2, OrderItem::where('sale_id', $sale->id)->count());
        $this->assertSame('56.00', $sale->items_total);
        $this->assertSame('56.00', $sale->total);
        $line = SaleOrder::where('sale_id', $sale->id)->sole();
        $this->assertSame($order->id, $line->order_id);
        $this->assertSame('56.00', $line->order_total);
        $this->assertNull($order->fresh()->sale_id, 'Rascunho não marca o pedido como cobrado.');
        $this->assertSame(OrderPaymentStatus::Pending, $order->fresh()->paymentStatus());
    }

    public function test_attaching_only_some_items_leaves_the_rest_free_for_another_sale(): void
    {
        $order = $this->order();
        [$pizza, $soda] = $this->itemIds($order);

        $first = app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);
        $second = app(AttachOrderItems::class)($this->newSale(), $order->id);

        $this->assertSame('40.00', $first->total);
        $this->assertSame('16.00', $second->total);
        $this->assertSame($first->id, OrderItem::find($pizza)->sale_id);
        $this->assertSame($second->id, OrderItem::find($soda)->sale_id);
    }

    public function test_the_same_item_never_enters_two_sales(): void
    {
        $order = $this->order();
        [$pizza] = $this->itemIds($order);
        app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('já foi lançado em outra venda');
        app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);
    }

    public function test_attaching_twice_to_the_same_sale_is_harmless(): void
    {
        $order = $this->order();
        $sale = $this->newSale();

        app(AttachOrderItems::class)($sale, $order->id);
        $again = app(AttachOrderItems::class)($sale, $order->id);

        $this->assertSame('56.00', $again->total);
        $this->assertSame(1, SaleOrder::where('sale_id', $sale->id)->count());
    }

    public function test_an_order_with_reserved_items_cannot_be_edited_or_cancelled(): void
    {
        $order = $this->order();
        Order::whereKey($order->id)->update(['status' => OrderStatus::Preparing]);
        app(AttachOrderItems::class)($this->newSale(), $order->id);

        try {
            app(CancelOrder::class)($order->fresh(), CancellationReason::CustomerGaveUp, null);
            $this->fail('Pedido com item em venda foi cancelado.');
        } catch (OrderTransitionException $exception) {
            $this->assertStringContainsString('tem itens em uma venda', $exception->getMessage());
        }

        $this->assertTrue($order->fresh()->hasItemsInSale());

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('tem itens em uma venda');
        app(UpdateOrderFromCart::class)($order->fresh(), [['type' => 'simple', 'product_id' => 1, 'flavor_ids' => [], 'quantity' => 1, 'note' => null]], ['payments' => [['payment_option_id' => 1, 'amount' => 1, 'change_for' => null]]]);
    }

    public function test_giving_the_items_back_releases_the_reservation_and_the_order_is_editable_again(): void
    {
        $order = $this->order();
        $sale = app(AttachOrderItems::class)($this->newSale(), $order->id);

        $sale = app(DetachOrderItems::class)($sale, $order->id);

        $this->assertSame(0, OrderItem::where('sale_id', $sale->id)->count());
        $this->assertSame(0, SaleOrder::where('sale_id', $sale->id)->count());
        $this->assertSame('0.00', $sale->total);
        $this->assertFalse($order->fresh()->hasItemsInSale());
    }

    public function test_detaching_one_item_keeps_the_other_in_the_sale(): void
    {
        $order = $this->order();
        [$pizza, $soda] = $this->itemIds($order);
        $sale = app(AttachOrderItems::class)($this->newSale(), $order->id);

        $sale = app(DetachOrderItems::class)($sale, $order->id, [$soda]);

        $this->assertSame('40.00', $sale->total);
        $this->assertSame($sale->id, OrderItem::find($pizza)->sale_id);
        $this->assertNull(OrderItem::find($soda)->sale_id);
        $this->assertSame('40.00', SaleOrder::where('sale_id', $sale->id)->sole()->order_total);
    }

    public function test_the_orders_freight_is_charged_once_and_returned_with_the_last_item(): void
    {
        $order = $this->order(fee: 5);
        [$pizza, $soda] = $this->itemIds($order);

        $first = app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);
        $second = app(AttachOrderItems::class)($this->newSale(), $order->id, [$soda]);

        $this->assertSame('5.00', $first->delivery_fee);
        $this->assertSame('45.00', $first->total);
        $this->assertSame('0.00', $second->delivery_fee, 'Outra venda ativa já cobrou o frete deste pedido.');
        $this->assertSame('16.00', $second->total);

        $first = app(DetachOrderItems::class)($first, $order->id);
        $this->assertSame('0.00', $first->delivery_fee, 'O frete volta ao tirar o último item do pedido.');
    }

    public function test_the_first_order_client_becomes_the_sale_client(): void
    {
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria', 'phone' => '11988887777']);
        $sale = app(AttachOrderItems::class)($this->newSale(), $this->order(client: $client)->id);

        $this->assertSame($client->id, $sale->client_id);
    }

    public function test_orders_that_cannot_be_sold_are_refused(): void
    {
        $started = $this->order();
        Order::whereKey($started->id)->update(['status' => OrderStatus::Started]);
        $cancelled = $this->order();
        Order::whereKey($cancelled->id)->update(['status' => OrderStatus::Cancelled]);
        $old = $this->order();
        Order::whereKey($old->id)->update(['created_at' => now()->subYear()]);

        foreach ([[$started, 'ainda não foi aceito'], [$cancelled, 'está cancelado'], [$old, 'anterior à ativação do PDV']] as [$order, $message]) {
            try {
                app(AttachOrderItems::class)($this->newSale(), $order->id);
                $this->fail("O pedido {$order->id} foi aceito.");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_items_that_do_not_belong_to_the_order_are_refused(): void
    {
        $order = $this->order();
        $other = $this->order();

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('não pertencem ao pedido');
        app(AttachOrderItems::class)($this->newSale(), $order->id, [$this->itemIds($other)[0]]);
    }

    public function test_cancelling_a_draft_releases_the_reservation_without_touching_stock(): void
    {
        $order = $this->order();
        $sale = app(AttachOrderItems::class)($this->newSale(), $order->id);

        $cancelled = app(CancelSale::class)($sale, 'Cliente desistiu');

        $this->assertSame(SaleStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->number);
        $this->assertSame(0, OrderItem::where('sale_id', $sale->id)->count());
        $this->assertFalse($order->fresh()->hasItemsInSale());
    }

    public function test_orders_already_reserved_do_not_show_up_as_awaiting_sale(): void
    {
        $order = $this->order();
        $this->assertTrue(Order::awaitingSale()->whereKey($order->id)->exists());

        app(AttachOrderItems::class)($this->newSale(), $order->id);
        $this->assertFalse(Order::awaitingSale()->whereKey($order->id)->exists());

        $other = $this->order();
        app(AttachOrderItems::class)($this->newSale(), $other->id, [$this->itemIds($other)[0]]);
        $this->assertTrue(Order::awaitingSale()->whereKey($other->id)->exists(), 'Ainda há item livre.');
    }

    public function test_an_order_split_across_two_sales_goes_from_pending_to_partial_to_paid(): void
    {
        $order = $this->order();
        [$pizza, $soda] = $this->itemIds($order);

        $first = app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);
        $this->pay($first, 40);
        app(FinalizeOpenSale::class)($first);

        $this->assertNull($order->fresh()->sale_id, 'Pedido parcial não ganha sale_id.');
        $this->assertSame(OrderPaymentStatus::PartiallyPaid, $order->fresh()->paymentStatus());

        $second = app(AttachOrderItems::class)($this->newSale(), $order->id, [$soda]);
        $this->assertSame(OrderPaymentStatus::PartiallyPaid, $order->fresh()->paymentStatus(), 'Reserva em rascunho ainda não é pagamento.');
        $this->pay($second, 16);
        app(FinalizeOpenSale::class)($second);

        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->paymentStatus());
        $this->assertNull($order->fresh()->sale_id, 'Pago em duas vendas: nenhuma cobrou o pedido inteiro.');
    }

    public function test_an_order_charged_whole_by_one_sale_gets_its_sale_id(): void
    {
        $order = $this->order();
        $sale = app(AttachOrderItems::class)($this->newSale(), $order->id);
        $this->pay($sale, 56);

        $finalized = app(FinalizeOpenSale::class)($sale);

        $this->assertSame($finalized->id, $order->fresh()->sale_id);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->paymentStatus());
    }

    public function test_the_freight_moves_to_the_other_open_sale_when_the_one_that_charged_it_drops(): void
    {
        $order = $this->order(fee: 10);
        [$pizza, $soda] = $this->itemIds($order);

        $first = app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);
        $second = app(AttachOrderItems::class)($this->newSale(), $order->id, [$soda]);
        $this->assertSame('0.00', $second->delivery_fee);

        app(DetachOrderItems::class)($first, $order->id);

        $second->refresh();
        $this->assertSame('10.00', $second->delivery_fee, 'O frete do pedido não pode se perder.');
        $this->assertSame('26.00', $second->total);
        $this->assertSame('10.00', SaleOrder::where('sale_id', $second->id)->sole()->delivery_fee_charged);
    }

    public function test_cancelling_a_sale_that_charged_the_freight_hands_it_to_the_other_open_sale(): void
    {
        $order = $this->order(fee: 10);
        [$pizza, $soda] = $this->itemIds($order);

        $first = app(AttachOrderItems::class)($this->newSale(), $order->id, [$pizza]);
        $second = app(AttachOrderItems::class)($this->newSale(), $order->id, [$soda]);

        app(CancelSale::class)($first, 'Erro');

        $this->assertSame('10.00', $second->fresh()->delivery_fee);
        $this->assertSame('26.00', $second->fresh()->total);
    }

    public function test_the_sale_client_follows_the_orders_only_while_they_all_share_the_same_client(): void
    {
        $maria = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria', 'phone' => '11988887777']);
        $joao = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'João', 'phone' => '11977776666']);
        $sale = $this->newSale();

        $sale = app(AttachOrderItems::class)($sale, $this->order(client: $maria)->id);
        $this->assertSame($maria->id, $sale->client_id);

        $joaoOrder = $this->order(client: $joao);
        $sale = app(AttachOrderItems::class)($sale, $joaoOrder->id);
        $this->assertNull($sale->client_id, 'Pedidos de clientes diferentes: sem comprador identificado.');

        $sale = app(DetachOrderItems::class)($sale, $joaoOrder->id);
        $this->assertSame($maria->id, $sale->client_id, 'Tirando o pedido do outro cliente, volta a ser a Maria.');

        $balcao = $this->order();
        $sale = app(AttachOrderItems::class)($sale, $balcao->id);
        $this->assertNull($sale->client_id, 'Um pedido sem cliente também desfaz a identificação automática.');
    }

    public function test_a_client_chosen_by_the_operator_is_never_changed_by_the_orders(): void
    {
        $maria = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria', 'phone' => '11988887777']);
        $joao = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'João', 'phone' => '11977776666']);
        $sale = $this->newSale();

        app(SetSaleClient::class)($sale, $joao->id);
        $sale = app(AttachOrderItems::class)($sale->fresh(), $this->order(client: $maria)->id);
        $this->assertSame($joao->id, $sale->client_id);

        app(SetSaleClient::class)($sale, null);
        $sale = app(AttachOrderItems::class)($sale->fresh(), $this->order(client: $maria)->id);
        $this->assertNull($sale->client_id, 'Limpar o cliente também é uma escolha do operador.');
    }

    public function test_the_direct_close_refuses_an_order_whose_items_are_reserved_in_a_draft(): void
    {
        $order = $this->order();
        app(AttachOrderItems::class)($this->newSale(), $order->id, [$this->itemIds($order)[0]]);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('já foi cobrado em outra venda');
        app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->cash->id, 'amount' => 56]]);
    }

    public function test_the_payment_progress_scope_matches_the_status_without_a_query_per_order(): void
    {
        $pending = $this->order();
        $partial = $this->order();
        $paid = $this->order();

        $first = app(AttachOrderItems::class)($this->newSale(), $partial->id, [$this->itemIds($partial)[0]]);
        $this->pay($first, 40);
        app(FinalizeOpenSale::class)($first);
        $whole = app(AttachOrderItems::class)($this->newSale(), $paid->id);
        $this->pay($whole, 56);
        app(FinalizeOpenSale::class)($whole);

        DB::enableQueryLog();
        $orders = Order::query()->withPaymentProgress()->whereKey([$pending->id, $partial->id, $paid->id])->orderBy('id')->get();
        $before = count(DB::getQueryLog());
        $statuses = $orders->map(fn (Order $order): OrderPaymentStatus => $order->paymentStatus())->all();

        $this->assertSame($before, count(DB::getQueryLog()), 'paymentStatus() não consulta o banco com o escopo.');
        $this->assertSame([OrderPaymentStatus::Pending, OrderPaymentStatus::PartiallyPaid, OrderPaymentStatus::Paid], $statuses);
    }

    public function test_a_cancelled_draft_is_not_called_just_draft(): void
    {
        $sale = $this->newSale();
        $this->assertSame('Rascunho', $sale->displayNumber());

        $cancelled = app(CancelSale::class)($sale, 'Desistência');

        $this->assertSame("Rascunho #{$sale->id}", $cancelled->displayNumber());
    }

    public function test_another_tenants_order_is_never_found(): void
    {
        $other = $this->makeSaleTenant('emporiodapizza');
        $foreign = $this->makeNfceOrder($other, [['product' => $this->makeProduct($other, 'Alheia', 40)]]);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('Pedido não encontrado');
        app(AttachOrderItems::class)($this->newSale(), $foreign->id);
    }

    private function pay(Sale $sale, float $amount): void
    {
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => $amount]);
    }
}

<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\ApplySaleDiscountPercent;
use App\Actions\Sales\RemoveSalePayment;
use App\Actions\Sales\RemoveStandaloneItem;
use App\Actions\Sales\SetSaleClient;
use App\Actions\Sales\SetSaleDeliveryFee;
use App\Actions\Sales\SplitSaleBill;
use App\Actions\Sales\StartSale;
use App\Actions\Sales\UpdateSalePayment;
use App\Actions\Sales\UpdateStandaloneItem;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Client;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * D-08: rascunho de venda (`open`). Itens avulsos, desconto %, frete, cliente e pagamentos
 * são salvos desde o primeiro lançamento; número, baixa de estoque e fiscal só ao finalizar.
 */
class SaleDraftItemsTest extends TestCase
{
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

    private function draft(): Sale
    {
        return app(StartSale::class)(User::factory()->create(['tenant_id' => $this->tenant->id]));
    }

    public function test_a_draft_starts_open_without_number_and_with_the_operator(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $sale = app(StartSale::class)($user);

        $this->assertSame(SaleStatus::Open, $sale->status);
        $this->assertNull($sale->number);
        $this->assertSame('Rascunho', $sale->displayNumber());
        $this->assertSame($user->id, $sale->opened_by_user_id);
        $this->assertSame($this->tenant->id, $sale->tenant_id);
        $this->assertSame('0.00', $sale->total);
        $this->assertSame(0, (int) $this->tenant->fresh()->sales_sequence, 'Rascunho não consome número de venda.');
    }

    public function test_a_draft_cannot_start_without_the_pdv(): void
    {
        $plain = $this->makeSaleTenant('semfeature', withPdv: false);
        $this->actAsTenant($plain);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('PDV não está ativo');
        app(StartSale::class)();
    }

    public function test_standalone_items_merge_on_repeated_clicks_and_update_the_totals(): void
    {
        $sale = $this->draft();
        $pizza = $this->makeProduct($this->tenant, 'Pizza', 40);
        $soda = $this->makeProduct($this->tenant, 'Refri', 8);

        app(AddStandaloneItem::class)($sale, $pizza->id);
        app(AddStandaloneItem::class)($sale, $pizza->id);
        app(AddStandaloneItem::class)($sale, $soda->id, 3);

        $this->assertSame(2, SaleItem::where('sale_id', $sale->id)->count());
        $line = SaleItem::where('sale_id', $sale->id)->where('product_id', $pizza->id)->sole();
        $this->assertSame(2, $line->quantity);
        $this->assertSame('80.00', $line->total);
        $this->assertSame('104.00', $sale->fresh()->items_total);
        $this->assertSame('104.00', $sale->fresh()->total);
    }

    public function test_update_and_remove_a_standalone_item(): void
    {
        $sale = $this->draft();
        $item = app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 40)->id);

        app(UpdateStandaloneItem::class)($sale, $item->id, 3);
        $this->assertSame('120.00', $sale->fresh()->total);

        app(RemoveStandaloneItem::class)($sale, $item->id);
        $this->assertSame('0.00', $sale->fresh()->total);
        $this->assertSame(0, SaleItem::where('sale_id', $sale->id)->count());
    }

    public function test_stock_is_checked_when_adding_but_only_decremented_on_finalize(): void
    {
        $sale = $this->draft();
        $product = $this->makeProduct($this->tenant, 'Vinho', 40, ['controls_stock' => true, 'stock_quantity' => 2]);

        app(AddStandaloneItem::class)($sale, $product->id, 2);
        $this->assertSame('2.00', (string) Product::find($product->id)->stock_quantity, 'Rascunho não baixa estoque.');

        $this->expectException(SaleException::class);
        app(AddStandaloneItem::class)($sale, $product->id);
    }

    public function test_the_percent_discount_follows_the_items_and_can_be_undone(): void
    {
        $sale = $this->draft();
        $item = app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 49.90)->id);

        app(ApplySaleDiscountPercent::class)($sale, '10');
        $sale->refresh();
        $this->assertSame('10.00', $sale->discount_percent);
        $this->assertSame('4.99', $sale->manual_discount_total);
        $this->assertSame('44.91', $sale->total);

        app(UpdateStandaloneItem::class)($sale, $item->id, 2);
        $this->assertSame('9.98', $sale->fresh()->manual_discount_total, 'O desconto acompanha os itens.');
        $this->assertSame('89.82', $sale->fresh()->total);

        app(ApplySaleDiscountPercent::class)($sale, null);
        $this->assertNull($sale->fresh()->discount_percent);
        $this->assertSame('99.80', $sale->fresh()->total);
    }

    public function test_an_invalid_discount_is_refused(): void
    {
        $sale = $this->draft();

        $this->expectException(SaleException::class);
        app(ApplySaleDiscountPercent::class)($sale, 101);
    }

    public function test_the_delivery_fee_is_editable_and_part_of_the_total(): void
    {
        $sale = $this->draft();
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 40)->id);

        app(SetSaleDeliveryFee::class)($sale, '6.50');

        $this->assertSame('6.50', $sale->fresh()->delivery_fee);
        $this->assertSame('46.50', $sale->fresh()->total);

        $this->expectException(SaleException::class);
        app(SetSaleDeliveryFee::class)($sale, -1);
    }

    public function test_the_client_is_linked_unlinked_and_never_comes_from_another_tenant(): void
    {
        $sale = $this->draft();
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria', 'phone' => '11988887777']);

        app(SetSaleClient::class)($sale, $client->id);
        $this->assertSame($client->id, $sale->fresh()->client_id);

        app(SetSaleClient::class)($sale, null);
        $this->assertNull($sale->fresh()->client_id);

        $other = $this->makeSaleTenant('emporiodapizza');
        $foreign = Client::create(['tenant_id' => $other->id, 'name' => 'Alheio', 'phone' => '11977776666']);

        $this->expectException(SaleException::class);
        app(SetSaleClient::class)($sale, $foreign->id);
    }

    public function test_payments_are_registered_edited_and_removed_keeping_the_amount_handed_over(): void
    {
        $sale = $this->draft();
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 40)->id);

        $payment = app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => '50.00']);

        $this->assertSame('50.00', $payment->amount);
        $this->assertSame('0.00', $payment->change_amount, 'O troco só é calculado ao finalizar.');
        $this->assertTrue($payment->is_cash);
        $this->assertSame($this->tenant->id, $payment->tenant_id);

        app(UpdateSalePayment::class)($sale, $payment->id, ['payment_option_id' => $this->card->id, 'amount' => '40.00', 'card_brand' => 'Visa']);
        $payment->refresh();
        $this->assertSame('Cartão', $payment->payment_option_name);
        $this->assertSame('40.00', $payment->amount);

        app(RemoveSalePayment::class)($sale, $payment->id);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
    }

    public function test_payment_rules_are_enforced_when_registering(): void
    {
        $sale = $this->draft();
        $strict = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Crédito', 'requires_card_brand' => true, 'requires_authorization_code' => true]);

        foreach ([
            ['payment_option_id' => $this->cash->id, 'amount' => 0],
            ['payment_option_id' => 999999, 'amount' => 10],
            ['payment_option_id' => $strict->id, 'amount' => 10],
        ] as $payment) {
            try {
                app(AddSalePayment::class)($sale, $payment);
                $this->fail('Pagamento inválido foi aceito.');
            } catch (SaleException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
    }

    public function test_split_the_bill_divides_what_is_left_in_cents(): void
    {
        $sale = $this->draft();
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 100)->id);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => '10.00']);

        $created = app(SplitSaleBill::class)($sale, 3, $this->card->id);

        $this->assertCount(3, $created);
        $this->assertEqualsCanonicalizing(['30.00', '30.00', '30.00'], array_map(fn (SalePayment $p): string => $p->amount, $created));
        $this->assertSame(9000, (int) round(SalePayment::where('sale_id', $sale->id)->where('payment_option_id', $this->card->id)->sum('amount') * 100));

        $other = $this->draft();
        app(AddStandaloneItem::class)($other, $this->makeProduct($this->tenant, 'Coisa', 10)->id);
        $parts = collect(app(SplitSaleBill::class)($other, 3, $this->card->id))->map(fn (SalePayment $p): string => $p->amount)->all();
        $this->assertSame(['3.34', '3.33', '3.33'], $parts, 'O resto de centavos vai para as primeiras partes.');
    }

    public function test_split_the_bill_refuses_without_value_or_with_bad_people_count(): void
    {
        $sale = $this->draft();

        foreach ([1, 51, 3] as $people) {
            try {
                app(SplitSaleBill::class)($sale, $people, $this->card->id);
                $this->fail('Divisão inválida foi aceita.');
            } catch (SaleException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_sale_that_is_no_longer_open_refuses_every_draft_change(): void
    {
        $sale = $this->draft();
        $product = $this->makeProduct($this->tenant, 'Pizza', 40);
        Sale::query()->whereKey($sale->id)->update(['status' => SaleStatus::Cancelled]);

        foreach ([
            fn () => app(AddStandaloneItem::class)($sale, $product->id),
            fn () => app(SetSaleDeliveryFee::class)($sale, 5),
            fn () => app(ApplySaleDiscountPercent::class)($sale, 5),
            fn () => app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => 5]),
        ] as $change) {
            try {
                $change();
                $this->fail('A venda fechada aceitou alteração.');
            } catch (SaleException $exception) {
                $this->assertStringContainsString('não está mais aberta', $exception->getMessage());
            }
        }
    }
}

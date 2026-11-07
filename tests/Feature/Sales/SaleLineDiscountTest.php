<?php

namespace Tests\Feature\Sales;

use App\Actions\Fiscal\BuildNfcePayload;
use App\Actions\Fiscal\ReserveFiscalNumber;
use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\ApplySaleDiscountPercent;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\DetachOrderItems;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\FinalizeSale;
use App\Actions\Sales\SetLineDiscount;
use App\Actions\Sales\SetSaleDeliveryFee;
use App\Actions\Sales\StartSale;
use App\Actions\Sales\UpdateStandaloneItem;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalPaymentMethod;
use App\Exceptions\SaleException;
use App\Filament\Tenant\Pages\Sales\NewSale;
use App\Models\FiscalDocumentItem;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleOrderItemDiscount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Fiscal\FiscalReportQuery;
use App\Support\Sales\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Desconto em reais por linha ("Desc. R$") e quantidade na Nova Venda: escala com a quantidade, não
 * passa do valor da linha, soma-se ao percentual (que incide sobre o que sobra) e sai na NFC-e por
 * linha, fechando centavo a centavo.
 */
class SaleLineDiscountTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $pix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function standalone(Sale $sale, float $price, int $quantity = 1): SaleItem
    {
        return app(AddStandaloneItem::class)($sale, $this->makeFiscalProduct($this->tenant, 'Item '.uniqid(), $price)->id, $quantity);
    }

    public function test_a_line_discount_lowers_the_total_and_is_cleared_with_zero(): void
    {
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 40, 2);

        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '10.00');
        $this->assertSame('80.00', $sale->fresh()->items_total);
        $this->assertSame('10.00', $sale->fresh()->manual_discount_total);
        $this->assertSame('70.00', $sale->fresh()->total);

        app(SetLineDiscount::class)($sale, 'standalone', $line->id, 0);
        $this->assertSame('80.00', $sale->fresh()->total);
    }

    public function test_the_discount_cannot_pass_the_value_of_the_line_nor_be_negative(): void
    {
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 40);

        foreach ([['40.01', 'passa do valor do item (R$ 40,00)'], [-1, 'desconto válido']] as [$amount, $message]) {
            try {
                app(SetLineDiscount::class)($sale, 'standalone', $line->id, $amount);
                $this->fail('Desconto inválido foi aceito.');
            } catch (SaleException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '40.00');
        $this->assertSame('0.00', $sale->fresh()->total, 'Desconto igual ao valor da linha é permitido (cortesia por desconto).');
    }

    public function test_the_percent_discount_applies_on_what_is_left_after_the_line_discounts(): void
    {
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 100);

        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '10.00');
        app(ApplySaleDiscountPercent::class)($sale, 10);

        // 100 − 10 (linha) − 9 (10% de 90) = 81
        $this->assertSame('19.00', $sale->fresh()->manual_discount_total);
        $this->assertSame('81.00', $sale->fresh()->total);

        // Mudar o desconto da linha refaz o percentual: 100 − 50 − 5 = 45
        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '50.00');
        $this->assertSame('45.00', $sale->fresh()->total);
    }

    public function test_the_discount_scales_with_the_quantity_and_never_passes_the_new_value(): void
    {
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 40, 2);
        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '10.00');

        app(UpdateStandaloneItem::class)($sale, $line->id, 4);
        $this->assertSame('20.00', $line->fresh()->discount_amount, 'Desconto por unidade preservado.');
        $this->assertSame('140.00', $sale->fresh()->total);

        app(UpdateStandaloneItem::class)($sale, $line->id, 1);
        $this->assertSame('5.00', $line->fresh()->discount_amount);
        $this->assertSame('35.00', $sale->fresh()->total);

        // Clique repetido no produto (soma 1 unidade) também escala.
        app(AddStandaloneItem::class)($sale, $line->product_id);
        $this->assertSame('10.00', $line->fresh()->discount_amount);
    }

    public function test_an_order_item_gets_its_own_discount_that_goes_away_when_the_item_is_given_back(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)], ['product' => $this->makeFiscalProduct($this->tenant, 'Refri', 8)]]);
        [$pizza, $soda] = OrderItem::where('order_id', $order->id)->orderBy('id')->pluck('id')->all();
        $sale = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);

        app(SetLineDiscount::class)($sale, 'order', $pizza, '5.00');
        $this->assertSame('43.00', $sale->fresh()->total);
        $this->assertSame('5.00', SaleOrderItemDiscount::where('sale_id', $sale->id)->sole()->amount);

        app(DetachOrderItems::class)($sale, $order->id, [$pizza]);
        $this->assertSame(0, SaleOrderItemDiscount::count());
        $this->assertSame('8.00', $sale->fresh()->total);

        // Devolvido e lançado de novo: volta sem desconto.
        app(AttachOrderItems::class)($sale, $order->id, [$pizza]);
        $this->assertSame('48.00', $sale->fresh()->total);
        $this->assertSame($sale->id, OrderItem::find($soda)->sale_id);
    }

    public function test_an_order_item_discount_cannot_pass_the_line_and_foreign_items_are_refused(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $pizza = OrderItem::where('order_id', $order->id)->value('id');
        $sale = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
        $otherDraft = app(StartSale::class)();

        try {
            app(SetLineDiscount::class)($sale, 'order', $pizza, '41.00');
            $this->fail('Desconto acima da linha foi aceito.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('passa do valor do item', $exception->getMessage());
        }

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('Item não encontrado nesta venda');
        app(SetLineDiscount::class)($otherDraft, 'order', $pizza, '1.00');
    }

    public function test_another_tenants_order_item_can_never_get_a_discount(): void
    {
        $other = $this->makeSaleTenant('emporiodapizza');
        $foreign = $this->makeNfceOrder($other, [['product' => $this->makeProduct($other, 'Alheia', 40)]]);
        $itemId = OrderItem::where('order_id', $foreign->id)->value('id');
        $sale = app(StartSale::class)();

        try {
            app(SetLineDiscount::class)($sale, 'order', $itemId, '1.00');
            $this->fail('Item de outro estabelecimento aceito.');
        } catch (SaleException) {
            $this->assertSame(0, SaleOrderItemDiscount::withoutGlobalScopes()->count());
        }
    }

    public function test_the_note_carries_each_lines_discount_plus_its_share_of_the_percent_and_closes_to_the_cent(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 49.90)]], deliveryFee: 3);
        $pizza = OrderItem::where('order_id', $order->id)->value('id');
        $sale = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
        $soda = $this->standalone($sale, 8, 2);

        app(SetLineDiscount::class)($sale, 'order', $pizza, '4.90');
        app(SetLineDiscount::class)($sale, 'standalone', $soda->id, '1.00');
        app(ApplySaleDiscountPercent::class)($sale, 10);

        // itens 49,90 + 16,00 = 65,90; linhas 4,90 + 1,00 = 5,90; 10% de 60,00 = 6,00; frete 3,00 → 57,00
        $sale = $sale->fresh();
        $this->assertSame('11.90', $sale->manual_discount_total);
        $this->assertSame('57.00', $sale->total);

        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '57.00']);
        $finalized = app(FinalizeOpenSale::class)($sale);

        $settings = $this->nfceSettings($this->tenant);
        $document = app(ReserveFiscalNumber::class)($finalized, $this->tenant, $settings, FiscalDocumentDecision::Manual);
        $payload = app(BuildNfcePayload::class)($document, $finalized, $settings);
        $items = $payload->body['items'];

        $discounts = array_map(fn (array $item): int => (int) round($item['discountAmount'] * 100), $items);
        $this->assertSame(1190, array_sum($discounts));
        $this->assertSame(
            5700,
            (int) round(collect($items)->sum(fn (array $item): float => $item['totalAmount'] + $item['othersAmount'] - $item['discountAmount']) * 100),
            'itens + adicionais/frete − descontos = total da venda',
        );
        // Cada linha leva o desconto próprio + parte do percentual (6,00 sobre 45,00 e 15,00).
        $this->assertSame([490 + 450, 100 + 150], $discounts);
    }

    public function test_the_snapshot_keeps_the_discount_of_each_line(): void
    {
        $this->fakeNfeIo('Processing');
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 100);
        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '12.34');
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '87.66']);
        $finalized = app(FinalizeOpenSale::class)($sale);

        $document = $this->issueNfce($finalized);

        $this->assertSame('12.34', FiscalDocumentItem::where('fiscal_document_id', $document->id)->sole()->discount_amount);
    }

    public function test_finalizing_charges_the_discounted_total(): void
    {
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 40, 2);
        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '10.00');
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '70.00']);

        $finalized = app(FinalizeOpenSale::class)($sale);

        $this->assertSame('70.00', $finalized->total);
        $this->assertSame('10.00', $finalized->manual_discount_total);
        $this->assertSame('80.00', $finalized->items_total);
    }

    public function test_cancelling_a_sale_keeps_its_history_and_gives_the_order_item_back_without_discount(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $pizza = OrderItem::where('order_id', $order->id)->value('id');
        $draft = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
        app(SetLineDiscount::class)($draft, 'order', $pizza, '5.00');

        app(CancelSale::class)($draft, 'Desistência');

        $this->assertNull(OrderItem::find($pizza)->sale_id);
        $again = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
        $this->assertSame('40.00', $again->total, 'A outra venda não herda o desconto da cancelada.');
        $this->assertSame('5.00', $draft->fresh()->manual_discount_total, 'O histórico da venda cancelada se mantém.');
    }

    public function test_a_line_discount_after_the_item_was_given_back_or_the_sale_closed_is_refused(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $pizza = OrderItem::where('order_id', $order->id)->value('id');
        $sale = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
        app(DetachOrderItems::class)($sale, $order->id);

        try {
            app(SetLineDiscount::class)($sale, 'order', $pizza, '1.00');
            $this->fail('Desconto em item devolvido foi aceito.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('Item não encontrado', $exception->getMessage());
        }

        $line = $this->standalone($sale, 40);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '40.00']);
        app(FinalizeOpenSale::class)($sale);

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('não está mais aberta');
        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '1.00');
    }

    public function test_a_fully_discounted_line_receives_no_share_of_the_percent_and_the_note_still_closes(): void
    {
        $sale = app(StartSale::class)();
        $free = $this->standalone($sale, 20);
        $this->standalone($sale, 80);
        app(SetLineDiscount::class)($sale, 'standalone', $free->id, '20.00');
        app(ApplySaleDiscountPercent::class)($sale, 10);

        // 20 + 80 = 100; linha toda descontada (20); 10% de 80 = 8; total 72
        $sale = $sale->fresh();
        $this->assertSame('28.00', $sale->manual_discount_total);
        $this->assertSame('72.00', $sale->total);

        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '72.00']);
        $finalized = app(FinalizeOpenSale::class)($sale);
        $settings = $this->nfceSettings($this->tenant);
        $document = app(ReserveFiscalNumber::class)($finalized, $this->tenant, $settings, FiscalDocumentDecision::Manual);

        $items = app(BuildNfcePayload::class)($document, $finalized, $settings)->body['items'];

        $this->assertSame([2000, 800], array_map(fn (array $item): int => (int) round($item['discountAmount'] * 100), $items));
        $this->assertSame(7200, (int) round(collect($items)->sum(fn (array $item): float => $item['totalAmount'] + $item['othersAmount'] - $item['discountAmount']) * 100));
    }

    public function test_broken_cents_with_line_discount_percent_and_freight_close_on_the_note(): void
    {
        $sale = app(StartSale::class)();
        $a = $this->standalone($sale, 33.33);
        $b = $this->standalone($sale, 66.67);
        app(SetLineDiscount::class)($sale, 'standalone', $a->id, '3.33');
        app(ApplySaleDiscountPercent::class)($sale, 7.5);
        app(SetSaleDeliveryFee::class)($sale, '4.99');

        $sale = $sale->fresh();
        $total = (int) round($sale->total * 100);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => Money::fromCents($total)]);
        $finalized = app(FinalizeOpenSale::class)($sale);
        $settings = $this->nfceSettings($this->tenant);
        $document = app(ReserveFiscalNumber::class)($finalized, $this->tenant, $settings, FiscalDocumentDecision::Manual);

        $items = app(BuildNfcePayload::class)($document, $finalized, $settings)->body['items'];

        $this->assertSame((int) round($finalized->manual_discount_total * 100), array_sum(array_map(fn (array $item): int => (int) round($item['discountAmount'] * 100), $items)));
        $this->assertSame($total, (int) round(collect($items)->sum(fn (array $item): float => $item['totalAmount'] + $item['othersAmount'] - $item['discountAmount']) * 100));
    }

    public function test_the_legacy_direct_close_still_builds_a_note_without_discount(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $sale = app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $this->pix->id, 'amount' => 40]]);
        $settings = $this->nfceSettings($this->tenant);
        $document = app(ReserveFiscalNumber::class)($sale, $this->tenant, $settings, FiscalDocumentDecision::Manual);

        $items = app(BuildNfcePayload::class)($document, $sale, $settings)->body['items'];

        $this->assertEquals(0, $items[0]['discountAmount']);
    }

    public function test_the_fiscal_report_shows_the_line_discount_and_matches_the_sale_total(): void
    {
        $sale = app(StartSale::class)();
        $line = $this->standalone($sale, 100);
        app(SetLineDiscount::class)($sale, 'standalone', $line->id, '12.00');
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '88.00']);
        $finalized = app(FinalizeOpenSale::class)($sale);
        $this->fakeNfeIo();
        $this->issueNfce($finalized, now());

        $report = FiscalReportQuery::forPeriod($this->tenant->id, now()->startOfMonth()->toDateString(), now()->toDateString());

        $summary = $report->summary();
        $this->assertSame(1200, $summary['discount_cents']);
        $this->assertSame(8800, $summary['total_cents']);
    }

    // ---- Tela -------------------------------------------------------------

    public function test_the_discount_field_restores_the_servers_value_after_a_refusal_and_never_creates_a_draft(): void
    {
        $this->actingAsCaixa();

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id)
            ->assertSeeHtml("then(() => \$el.value = '0,00')");

        // Tela velha: o rascunho já foi descartado e o campo não cria outro só para recusar.
        $stale = Livewire::test(NewSale::class)->dispatch('product-selected', productId: $this->makeFiscalProduct($this->tenant, 'Refri', 8)->id);
        $draftId = SaleItem::latest('id')->value('sale_id');
        SaleItem::query()->where('sale_id', $draftId)->delete();
        Sale::query()->whereKey($draftId)->delete();
        $before = Sale::count();

        $stale->call('updateLineDiscount', 'standalone', 999, 5)->assertNotified();

        $this->assertSame($before, Sale::count());
    }

    private function actingAsCaixa(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('Caixa');
        $this->actingAs($user);
    }

    public function test_the_screen_shows_quantity_buttons_and_the_discount_field_on_every_line(): void
    {
        $this->actingAsCaixa();
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);

        Livewire::test(NewSale::class)
            ->call('launchOrder', $order->id)
            ->dispatch('product-selected', productId: $this->makeFiscalProduct($this->tenant, 'Refri', 8)->id)
            ->assertSee('Desc. R$')
            ->assertSeeHtml("changeLineQuantity('order'")
            ->assertSeeHtml("changeLineQuantity('standalone'")
            ->assertSeeHtml("updateLineDiscount('order'")
            ->assertSeeHtml("updateLineDiscount('standalone'");
    }

    public function test_the_discount_field_writes_to_the_database_and_refuses_more_than_the_line(): void
    {
        $this->actingAsCaixa();
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);

        $component = Livewire::test(NewSale::class)->dispatch('product-selected', productId: $product->id);
        $line = SaleItem::sole();

        $component->call('updateLineDiscount', 'standalone', $line->id, 5.5)->assertSee('R$ 34,50');
        $this->assertSame('5.50', $line->fresh()->discount_amount);

        $component->call('updateLineDiscount', 'standalone', $line->id, 99)->assertNotified();
        $this->assertSame('5.50', $line->fresh()->discount_amount);
    }

    public function test_quantity_buttons_on_an_order_line_only_warn(): void
    {
        $this->actingAsCaixa();
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $item = OrderItem::where('order_id', $order->id)->first();

        Livewire::test(NewSale::class)
            ->call('launchOrder', $order->id)
            ->call('changeLineQuantity', 'order', $item->id, 5)
            ->assertNotified('Item lançado de pedido');

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_the_quantity_buttons_still_change_a_standalone_line(): void
    {
        $this->actingAsCaixa();
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);

        Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $product->id)
            ->call('changeLineQuantity', 'standalone', SaleItem::sole()->id, 3);

        $this->assertSame(3, SaleItem::sole()->quantity);
    }
}

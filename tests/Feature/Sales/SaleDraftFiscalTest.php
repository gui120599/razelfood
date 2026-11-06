<?php

namespace Tests\Feature\Sales;

use App\Actions\Fiscal\BuildNfcePayload;
use App\Actions\Fiscal\ReserveFiscalNumber;
use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\ApplySaleDiscountPercent;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\SetSaleClient;
use App\Actions\Sales\SetSaleDeliveryFee;
use App\Actions\Sales\StartSale;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalPaymentMethod;
use App\Jobs\IssueFiscalDocumentJob;
use App\Models\FiscalDocumentItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\Fiscal\CentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Venda criada pelo rascunho + NFC-e: desconto manual rateado por linha (`discountAmount`),
 * comprador pelo cliente da venda e emissão automática depois da resposta.
 */
class SaleDraftFiscalTest extends TestCase
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
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function build(Sale $sale): array
    {
        $settings = $this->nfceSettings($this->tenant);
        $document = app(ReserveFiscalNumber::class)($sale, $this->tenant, $settings, FiscalDocumentDecision::Manual);

        return app(BuildNfcePayload::class)($document, $sale, $settings)->body;
    }

    private function finalizedDraft(callable $configure, string $payment): Sale
    {
        $sale = app(StartSale::class)();
        $configure($sale);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => $payment]);

        return app(FinalizeOpenSale::class)($sale->fresh());
    }

    public function test_the_manual_discount_is_split_across_the_lines_and_the_payload_closes(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 49.90)]], deliveryFee: 3);
        $soda = $this->makeFiscalProduct($this->tenant, 'Refri', 8);

        $sale = $this->finalizedDraft(function (Sale $draft) use ($order, $soda): void {
            app(AttachOrderItems::class)($draft, $order->id);
            app(AddStandaloneItem::class)($draft, $soda->id, 2);
            app(ApplySaleDiscountPercent::class)($draft, 10);
        }, '62.31');

        // itens 49,90 + 16,00 = 65,90; desconto 10% = 6,59; frete 3,00 → 62,31
        $this->assertSame('62.31', $sale->total);
        $this->assertSame('6.59', $sale->manual_discount_total);

        $items = $this->build($sale)['items'];

        $discount = (int) round(collect($items)->sum('discountAmount') * 100);
        $this->assertSame(659, $discount);
        $net = (int) round(collect($items)->sum(fn (array $item): float => $item['totalAmount'] + $item['othersAmount'] - $item['discountAmount']) * 100);
        $this->assertSame(6231, $net, 'itens + adicionais/frete − desconto = total da venda');
        $this->assertCount(2, $items);
        $this->assertTrue(collect($items)->every(fn (array $item): bool => $item['discountAmount'] > 0));
    }

    public function test_the_snapshot_keeps_the_discount_of_each_line(): void
    {
        $this->fakeNfeIo('Processing');
        $sale = $this->finalizedDraft(function (Sale $draft): void {
            app(AddStandaloneItem::class)($draft, $this->makeFiscalProduct($this->tenant, 'Pizza', 100)->id);
            app(ApplySaleDiscountPercent::class)($draft, 20);
        }, '80.00');

        $document = $this->issueNfce($sale);

        $this->assertSame('20.00', FiscalDocumentItem::where('fiscal_document_id', $document->id)->sole()->discount_amount);
    }

    public function test_a_sale_without_discount_keeps_discount_amount_zero(): void
    {
        $sale = $this->finalizedDraft(function (Sale $draft): void {
            app(AddStandaloneItem::class)($draft, $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id);
        }, '40.00');

        $this->assertEquals(0, $this->build($sale)['items'][0]['discountAmount']);
    }

    public function test_the_sale_client_is_the_buyer_even_for_standalone_only_sales(): void
    {
        $client = $this->makeNfceClient($this->tenant, ['email' => 'maria@example.com']);

        $sale = $this->finalizedDraft(function (Sale $draft) use ($client): void {
            app(AddStandaloneItem::class)($draft, $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id);
            app(SetSaleClient::class)($draft, $client->id);
        }, '40.00');

        $buyer = $this->build($sale)['buyer'];

        $this->assertSame('Maria da Silva', $buyer['name']);
        $this->assertSame(self::VALID_CPF, $buyer['federalTaxNumber']);
    }

    public function test_without_a_sale_client_a_standalone_only_sale_has_no_buyer(): void
    {
        $sale = $this->finalizedDraft(function (Sale $draft): void {
            app(AddStandaloneItem::class)($draft, $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id);
        }, '40.00');

        $this->assertNull($this->build($sale)['buyer']);
    }

    public function test_the_freight_typed_on_the_sale_is_what_the_note_carries(): void
    {
        $sale = $this->finalizedDraft(function (Sale $draft): void {
            app(AddStandaloneItem::class)($draft, $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id);
            app(SetSaleDeliveryFee::class)($draft, '6.00');
        }, '46.00');

        $item = $this->build($sale)['items'][0];

        $this->assertEquals(6, $item['othersAmount']);
    }

    public function test_finalizing_a_draft_with_an_automatic_payment_dispatches_the_note_after_the_response(): void
    {
        $auto = $this->makeFiscalPaymentOption($this->tenant, 'Pix auto', FiscalPaymentMethod::InstantPayment, auto: true);
        Bus::fake();

        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $auto->id, 'amount' => '40.00']);
        $finalized = app(FinalizeOpenSale::class)($sale);

        Bus::assertDispatchedAfterResponse(IssueFiscalDocumentJob::class, fn (IssueFiscalDocumentJob $job): bool => $job->saleId === $finalized->id && $job->tenantId === $this->tenant->id);
    }

    public function test_a_draft_alone_never_dispatches_anything_fiscal(): void
    {
        $auto = $this->makeFiscalPaymentOption($this->tenant, 'Pix auto', FiscalPaymentMethod::InstantPayment, auto: true);
        Bus::fake();

        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $this->makeFiscalProduct($this->tenant, 'Pizza', 40)->id);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $auto->id, 'amount' => '40.00']);

        Bus::assertNotDispatchedAfterResponse(IssueFiscalDocumentJob::class);
    }

    public function test_proportional_split_never_loses_or_creates_a_cent(): void
    {
        $this->assertSame([5, 5], CentAllocator::proportional(10, [1, 1]));
        $this->assertSame([1, 1, 0], CentAllocator::proportional(2, [1, 1, 1]), 'Empate: as linhas mais antigas ficam com o centavo.');
        $this->assertSame([0, 7], CentAllocator::proportional(7, [0, 100]));
        $this->assertSame(659, array_sum(CentAllocator::proportional(659, [4990, 1600])));
        $this->assertSame([0, 0], CentAllocator::proportional(0, [0, 0]));

        foreach ([[1, 3, 7, 11], [4990, 1600, 1], [3333, 3333, 3334]] as $weights) {
            foreach ([1, 7, 99, 1000, 12345] as $total) {
                $this->assertSame($total, array_sum(CentAllocator::proportional($total, $weights)));
            }
        }
    }

    public function test_proportional_split_refuses_invalid_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CentAllocator::proportional(5, [0, 0]);
    }
}

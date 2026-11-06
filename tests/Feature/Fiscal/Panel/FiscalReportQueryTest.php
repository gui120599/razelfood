<?php

namespace Tests\Feature\Fiscal\Panel;

use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\ApplySaleDiscountPercent;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\StartSale;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Models\Category;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Fiscal\FiscalReportQuery;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Relatório fiscal: só NFC-e autorizadas, pelo período de autorização, agrupadas a partir do
 * snapshot da emissão; totais fecham com as notas; isolamento entre tenants.
 */
class FiscalReportQueryTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $pix;

    private PaymentOption $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
        $this->cash = $this->makeFiscalPaymentOption($this->tenant, 'Dinheiro', FiscalPaymentMethod::Cash, isCash: true);
        $this->fakeNfeIo();
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function report(?string $start = null, ?string $end = null, ?Tenant $tenant = null): FiscalReportQuery
    {
        return FiscalReportQuery::forPeriod(($tenant ?? $this->tenant)->id, $start ?? now()->startOfMonth()->toDateString(), $end ?? now()->toDateString());
    }

    private function note(array $items, float $fee = 0, ?CarbonInterface $authorizedAt = null, ?PaymentOption $option = null, ?array $payments = null): FiscalDocument
    {
        $order = $this->makeNfceOrder($this->tenant, $items, $fee);

        return $this->issueNfce($this->finalizeNfceSale([$order], $option ?? $this->pix, $payments), $authorizedAt ?? now());
    }

    public function test_it_groups_by_every_dimension_and_the_totals_match_the_notes(): void
    {
        $pizzas = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas']);
        $drinks = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas']);
        $pizza = $this->makeFiscalProduct($this->tenant, 'Calabresa', 40, ['category_id' => $pizzas->id]);
        $soda = $this->makeFiscalProduct($this->tenant, 'Refrigerante', 8, ['category_id' => $drinks->id, 'ncm' => '22021000', 'cfop' => '5405', 'csosn' => '500']);

        $this->note([['product' => $pizza, 'quantity' => 2, 'addons_total' => 5], ['product' => $soda]], fee: 4);
        $this->note([['product' => $pizza]]);

        $report = $this->report();
        $summary = $report->summary();

        // nota 1: 80 + 8 itens, 10 adicionais + 4 frete; nota 2: 40
        $this->assertSame(['notes' => 2, 'items' => 3, 'items_cents' => 12800, 'others_cents' => 1400, 'discount_cents' => 0, 'total_cents' => 14200], $summary);

        $ncm = $report->group('ncm');
        $this->assertSame(['19059090', '22021000'], $ncm['rows']->pluck('label')->all());
        $this->assertSame([2, 1], $ncm['rows']->pluck('items')->all());
        $this->assertSame(14200, $ncm['total']['total_cents']);
        $this->assertSame(14200, $ncm['rows']->sum('total_cents'));

        $category = $report->group('category');
        $this->assertSame(['Pizzas', 'Bebidas'], $category['rows']->pluck('label')->all());
        $this->assertSame(3, $category['rows'][0]['quantity']);

        $this->assertSame(['Calabresa', 'Refrigerante'], $report->group('product')['rows']->pluck('label')->all());
        $this->assertEqualsCanonicalizing(['CFOP 5102 / CSOSN 102', 'CFOP 5405 / CSOSN 500'], $report->group('cfop_csosn')['rows']->pluck('label')->all());
        $this->assertSame([now()->format('d/m/Y')], $report->group('day')['rows']->pluck('label')->all());
        $this->assertSame('TOTAL', $report->group('ncm')['total']['label']);
    }

    public function test_the_payment_grouping_is_net_of_change_and_matches_the_total(): void
    {
        $pizza = $this->makeFiscalProduct($this->tenant, 'Pizza', 70);
        $this->note([['product' => $pizza]], payments: [
            ['payment_option_id' => $this->cash->id, 'amount' => 50],
            ['payment_option_id' => $this->pix->id, 'amount' => 30],
        ]);

        $payment = $this->report()->group('payment');

        $this->assertSame(['Dinheiro' => 4000, 'Pix' => 3000], $payment['rows']->pluck('total_cents', 'label')->all());
        $this->assertSame(7000, $payment['total']['total_cents']);
        $this->assertNull($payment['rows'][0]['items']);
    }

    public function test_only_standing_notes_inside_the_authorization_period_count(): void
    {
        $pizza = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $inside = $this->note([['product' => $pizza]], authorizedAt: now()->subDays(2));
        $this->note([['product' => $pizza]], authorizedAt: now()->subDays(40));
        $cancelled = $this->note([['product' => $pizza]]);
        $cancelled->update(['status' => FiscalDocumentStatus::Cancelled, 'cancelled_at' => now()]);
        $requested = $this->note([['product' => $pizza]]);
        $requested->update(['status' => FiscalDocumentStatus::CancelRequested]);
        $processing = $this->note([['product' => $pizza]]);
        $processing->update(['status' => FiscalDocumentStatus::Processing, 'authorized_at' => null]);
        $failed = $this->note([['product' => $pizza]]);
        $failed->update(['status' => FiscalDocumentStatus::Failed]);

        $report = $this->report(now()->subDays(5)->toDateString(), now()->toDateString());

        // Emitida e com cancelamento solicitado continuam válidas na SEFAZ; cancelada, em processamento e rejeitada ficam fora.
        $this->assertSame(2, $report->summary()['notes']);
        $this->assertEqualsCanonicalizing([$inside->number, $requested->number], $report->notes()->pluck('number')->all());
        $this->assertSame(8000, $report->summary()['total_cents']);

        $this->assertSame(1, $this->report(now()->subDays(45)->toDateString(), now()->subDays(35)->toDateString())->summary()['notes']);
    }

    public function test_the_period_edges_are_inclusive_by_day_and_inverted_or_invalid_dates_are_fixed(): void
    {
        $pizza = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $this->note([['product' => $pizza]], authorizedAt: now()->startOfDay());
        $this->note([['product' => $pizza]], authorizedAt: now()->endOfDay());

        $this->assertSame(2, $this->report(now()->toDateString(), now()->toDateString())->summary()['notes']);
        $this->assertSame(2, $this->report(now()->toDateString(), now()->subDays(3)->toDateString())->summary()['notes']);
        $this->assertSame(2, FiscalReportQuery::forPeriod($this->tenant->id, 'lixo', 'tambem-lixo')->summary()['notes']);
    }

    public function test_the_report_is_isolated_between_tenants(): void
    {
        $pizza = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $this->note([['product' => $pizza]]);

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');
        $otherPix = $this->makeFiscalPaymentOption($other, 'Pix', FiscalPaymentMethod::InstantPayment);
        $otherSale = $this->finalizeNfceSale([$this->makeNfceOrder($other, [['product' => $this->makeFiscalProduct($other, 'Esfiha', 10)]])], $otherPix);
        $this->issueNfce($otherSale);

        $this->assertSame(['notes' => 1, 'items' => 1, 'items_cents' => 4000, 'others_cents' => 0, 'discount_cents' => 0, 'total_cents' => 4000], $this->report()->summary());
        $this->assertSame(['Esfiha'], $this->report(tenant: $other)->group('product')['rows']->pluck('label')->all());
        $this->assertSame(['Pix' => 4000], $this->report()->group('payment')['rows']->pluck('total_cents', 'label')->all());
    }

    public function test_a_manual_discount_is_its_own_column_and_the_totals_still_match_the_notes(): void
    {
        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $this->makeFiscalProduct($this->tenant, 'Pizza', 100)->id);
        app(ApplySaleDiscountPercent::class)($sale, 10);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->pix->id, 'amount' => '90.00']);
        $finalized = app(FinalizeOpenSale::class)($sale);
        $this->issueNfce($finalized, now());

        $summary = $this->report()->summary();

        $this->assertSame(['notes' => 1, 'items' => 1, 'items_cents' => 10000, 'others_cents' => 0, 'discount_cents' => 1000, 'total_cents' => 9000], $summary);
        $ncm = $this->report()->group('ncm');
        $this->assertSame(1000, $ncm['rows'][0]['discount_cents']);
        $this->assertSame(9000, $ncm['total']['total_cents']);
        $this->assertSame(9000, $this->report()->notesTotals()['total_cents'], 'Total das notas = soma dos itens − desconto.');
        $this->assertSame(['Pix' => 9000], $this->report()->group('payment')['rows']->pluck('total_cents', 'label')->all());
    }

    public function test_the_snapshot_keeps_the_report_stable_after_catalog_changes(): void
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $this->note([['product' => $product]]);

        Product::query()->whereKey($product->id)->update(['ncm' => '11111111', 'name' => 'Renomeada']);

        $this->assertSame(['19059090'], $this->report()->group('ncm')['rows']->pluck('label')->all());
        $this->assertSame(['Pizza'], $this->report()->group('product')['rows']->pluck('label')->all());
    }

    public function test_notes_list_shows_the_client_and_the_sale_total(): void
    {
        $client = $this->makeNfceClient($this->tenant, ['name' => 'Maria da Silva']);
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]], client: $client);
        $this->issueNfce($this->finalizeNfceSale([$order], $this->pix), now());
        $this->note([['product' => $this->makeFiscalProduct($this->tenant, 'Esfiha', 10)]]);

        $notes = $this->report()->notes();

        $this->assertSame(['Maria da Silva', 'Consumidor'], $notes->pluck('client')->all());
        $this->assertSame([4000, 1000], $notes->pluck('total_cents')->all());
    }

    public function test_unused_numbers_are_old_reserved_failed_or_rejected_only_and_per_tenant(): void
    {
        $pizza = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $make = function (FiscalDocumentStatus $status, CarbonInterface $createdAt) use ($pizza): FiscalDocument {
            $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $pizza]])], $this->pix);
            $document = $this->makeFiscalDocumentFor($sale, $status);
            FiscalDocument::query()->whereKey($document->id)->update(['created_at' => $createdAt]);

            return $document->refresh();
        };

        $old = now()->subHours(30);
        $reserved = $make(FiscalDocumentStatus::Reserved, $old);
        $failedToSend = $make(FiscalDocumentStatus::FailedToSend, $old);
        $rejected = $make(FiscalDocumentStatus::Failed, $old);
        $make(FiscalDocumentStatus::Issued, $old);
        $make(FiscalDocumentStatus::Cancelled, $old);
        $make(FiscalDocumentStatus::Processing, $old);
        $make(FiscalDocumentStatus::Reserved, now()->subHours(2));
        $rejected->update(['error_message' => 'Rejeição 539']);

        $unused = $this->report()->unusedNumbers();

        $this->assertEqualsCanonicalizing([$reserved->number, $failedToSend->number, $rejected->number], $unused->pluck('number')->all());
        $this->assertStringContainsString('Rejeição 539', $unused->firstWhere('number', $rejected->number)['reason']);
        $this->assertCount(3, FiscalDocument::query()->unusedNumbers()->get());

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');
        $this->assertCount(0, $this->report(tenant: $other)->unusedNumbers());
    }

    public function test_the_csv_lines_carry_the_summary_and_each_requested_table(): void
    {
        $this->note([['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);

        $lines = $this->report()->toCsv(['ncm', 'payment', 'invalida']);
        $flat = collect($lines)->map(fn (array $line): string => implode(';', $line))->implode("\n");

        $this->assertStringContainsString('Notas autorizadas;1', $flat);
        $this->assertStringContainsString('Total das notas;40,00', $flat);
        $this->assertStringContainsString('Por NCM', $flat);
        $this->assertStringContainsString('19059090;1;1;40,00;0,00;0,00;40,00', $flat);
        $this->assertStringContainsString('Por forma de pagamento', $flat);
        $this->assertStringNotContainsString('invalida', $flat);
        $this->assertSame(['ncm', 'payment'], FiscalReportQuery::dimensionsFrom(['payment', 'x', 'ncm']));
    }

    public function test_a_note_whose_cancellation_failed_is_still_standing(): void
    {
        $note = $this->note([['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $note->update(['status' => FiscalDocumentStatus::CancelFailed]);

        $this->assertSame(1, $this->report()->summary()['notes']);
        $this->assertSame(4000, $this->report()->summary()['total_cents']);
    }

    public function test_notes_without_a_saved_snapshot_are_flagged_and_all_blocks_agree_on_the_notes(): void
    {
        $this->note([['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $legacySale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Esfiha', 10)]])], $this->pix);
        $this->makeFiscalDocumentFor($legacySale, FiscalDocumentStatus::Issued)->update(['authorized_at' => now()]);

        $report = $this->report();

        $this->assertSame(1, $report->notesWithoutItems());
        $this->assertSame(2, $report->summary()['notes']);
        $this->assertSame(2, $report->notesTotals()['count']);
        $this->assertSame(5000, $report->notesTotals()['total_cents']);
        $this->assertSame(4000, $report->summary()['total_cents']);
        $this->assertSame(5000, $report->group('payment')['total']['total_cents']);
        $this->assertSame([true, false], $report->notes()->pluck('has_items')->sort()->values()->reverse()->values()->all());
        $this->assertStringContainsString('1 nota(s)', collect($report->toCsv(['ncm']))->flatten()->implode(' '));
    }

    public function test_an_ambiguous_send_is_flagged_in_the_unused_numbers(): void
    {
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $this->pix);
        $document = $this->makeFiscalDocumentFor($sale, FiscalDocumentStatus::FailedToSend);
        FiscalDocument::query()->whereKey($document->id)->update(['provider_id' => null, 'sent_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);

        $unused = $this->report()->unusedNumbers();

        $this->assertCount(1, $unused);
        $this->assertStringContainsString('Envio incerto', $unused[0]['reason']);
    }

    public function test_the_notes_list_can_be_limited_while_the_totals_cover_everything(): void
    {
        foreach (range(1, 3) as $i) {
            $this->note([['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.$i, 10)]]);
        }

        $report = $this->report();

        $this->assertCount(2, $report->notes(2));
        $this->assertCount(3, $report->notes());
        $this->assertSame(['count' => 3, 'total_cents' => 3000], $report->notesTotals());
    }

    public function test_the_client_is_the_one_of_the_first_order_of_the_sale(): void
    {
        $first = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]], client: $this->makeNfceClient($this->tenant, ['name' => 'Primeiro']));
        $second = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Esfiha', 10)]], client: $this->makeNfceClient($this->tenant, ['name' => 'Segundo']));
        $this->issueNfce($this->finalizeNfceSale([$first, $second], $this->pix), now());

        $this->assertSame(['Primeiro'], $this->report()->notes()->pluck('client')->all());
    }
}

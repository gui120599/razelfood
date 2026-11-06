<?php

namespace Tests\Feature\Fiscal\Panel;

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Exceptions\FiscalDocumentException;
use App\Models\Category;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Os itens da nota são gravados na emissão, exatamente como foram enviados à NFE.io: base do
 * relatório fiscal, imune a mudanças posteriores no catálogo.
 */
class FiscalItemSnapshotTest extends TestCase
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
        $this->fakeNfeIo();
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(FiscalDocument $document): array
    {
        return FiscalDocumentItem::query()->where('fiscal_document_id', $document->id)->orderBy('position')->get()
            ->map(fn (FiscalDocumentItem $item): array => $item->only(['position', 'description', 'category_name', 'ncm', 'cfop', 'csosn', 'origin_code', 'unit', 'quantity', 'unit_amount', 'total_amount', 'others_amount']))
            ->all();
    }

    public function test_the_items_are_saved_as_sent_with_freight_and_addons_in_others(): void
    {
        $parent = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas']);
        $child = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Salgadas', 'parent_id' => $parent->id]);
        $pizza = $this->makeFiscalProduct($this->tenant, 'Calabresa', 40, ['category_id' => $child->id]);
        $soda = $this->makeFiscalProduct($this->tenant, 'Refrigerante', 8, ['ncm' => '22021000', 'cfop' => '5405', 'csosn' => '500']);

        $order = $this->makeNfceOrder($this->tenant, [
            ['product' => $pizza, 'quantity' => 2, 'addons_total' => 5],
            ['product' => $soda],
        ], deliveryFee: 3);
        $document = $this->issueNfce($this->finalizeNfceSale([$order], $this->pix));

        $items = $this->snapshot($document);

        $this->assertCount(2, $items);
        $this->assertSame('CALABRESA', $items[0]['description']);
        $this->assertSame('Pizzas › Salgadas', $items[0]['category_name']);
        $this->assertSame(['19059090', '5102', '102', '0', 'UN', 2], [$items[0]['ncm'], $items[0]['cfop'], $items[0]['csosn'], $items[0]['origin_code'], $items[0]['unit'], $items[0]['quantity']]);
        $this->assertEquals([40, 80], [$items[0]['unit_amount'], $items[0]['total_amount']]);
        // adicionais (5 × 2) + metade do frete (R$ 3,00 em 2 linhas: 1,50 cada)
        $this->assertEquals(11.50, $items[0]['others_amount']);
        $this->assertSame(['22021000', '5405', '500'], [$items[1]['ncm'], $items[1]['cfop'], $items[1]['csosn']]);
        $this->assertEquals(1.50, $items[1]['others_amount']);
        $this->assertSame([1, 2], [$items[0]['position'], $items[1]['position']]);

        $total = collect($items)->sum(fn (array $item): float => (float) $item['total_amount'] + (float) $item['others_amount']);
        $this->assertEquals((float) $order->grand_total, $total);
    }

    public function test_a_flavored_pizza_and_a_standalone_item_are_snapshot_lines_too(): void
    {
        $category = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas Doces', 'ncm' => '19059090', 'cfop' => '5102', 'csosn' => '102', 'origin_code' => '0', 'commercial_unit' => 'UN']);
        $a = $this->makeProduct($this->tenant, 'Chocolate', 50, ['category_id' => $category->id]);
        $b = $this->makeProduct($this->tenant, 'Morango', 50, ['category_id' => $category->id]);
        $base = $this->makeFiscalProduct($this->tenant, 'Pizza Grande', 50);
        $soda = $this->makeFiscalProduct($this->tenant, 'Refrigerante', 8);

        $order = $this->makeNfceOrder($this->tenant, [['product' => $base, 'unit_price' => 50, 'flavors' => [$a->id, $b->id]]]);
        $document = $this->issueNfce($this->finalizeNfceSale([$order], $this->pix, standalone: [['product_id' => $soda->id, 'quantity' => 2]]));

        $items = $this->snapshot($document);

        $this->assertSame(['PIZZAS DOCES CHOCOLATE / MORANGO', 'REFRIGERANTE'], array_column($items, 'description'));
        $this->assertSame('Pizzas Doces', $items[0]['category_name']);
        $this->assertSame(2, $items[1]['quantity']);
    }

    public function test_a_resend_replaces_the_previous_snapshot_instead_of_duplicating_it(): void
    {
        $this->queueNfeIo(['message' => 'erro'], 400);
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $this->pix);

        $failed = $this->issueNfce($sale);
        $this->assertCount(1, $this->snapshot($failed));

        $resent = $this->issueNfce($sale);

        $this->assertSame($failed->id, $resent->id);
        $this->assertCount(1, $this->snapshot($resent));
        $this->assertSame(1, FiscalDocumentItem::query()->count());
    }

    public function test_nothing_is_saved_when_the_payload_cannot_be_built(): void
    {
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $this->pix);
        Sale::query()->whereKey($sale->id)->update(['total' => 99]);

        try {
            $this->issueNfce($sale->refresh());
            $this->fail('Deveria recusar totais que não fecham.');
        } catch (FiscalDocumentException) {
        }

        $this->assertSame(0, FiscalDocumentItem::query()->count());
    }

    public function test_editing_the_catalog_afterwards_does_not_change_the_snapshot(): void
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Calabresa', 40);
        $document = $this->issueNfce($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $product]])], $this->pix));

        Product::query()->whereKey($product->id)->update(['name' => 'Outro nome', 'ncm' => '99999999']);

        $this->assertSame(['CALABRESA', '19059090'], [$this->snapshot($document)[0]['description'], $this->snapshot($document)[0]['ncm']]);
    }

    public function test_snapshots_are_isolated_between_tenants(): void
    {
        $document = $this->issueNfce($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $this->pix));

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');

        $this->assertSame(0, FiscalDocumentItem::query()->count());
        $this->assertSame(1, FiscalDocumentItem::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame($other->id, CurrentTenant::id());
        $this->assertSame($this->tenant->id, $document->tenant_id);
    }

    public function test_long_category_names_are_trimmed_to_fit_the_column(): void
    {
        $parent = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => str_repeat('P', 250)]);
        $child = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => str_repeat('F', 250), 'parent_id' => $parent->id]);
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40, ['category_id' => $child->id]);

        $document = $this->issueNfce($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $product]])], $this->pix));

        $item = $this->snapshot($document)[0];
        $this->assertLessThanOrEqual(255, mb_strlen($item['category_name']));
        $this->assertSame(FiscalDocumentStatus::Issued, $document->status);
    }

    public function test_the_first_emission_never_runs_a_delete_on_the_items_table(): void
    {
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $this->pix);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->issueNfce($sale);

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'delete from `fiscal_document_items`')));
    }
}

<?php

namespace Tests\Feature\Fiscal\Nfce;

use App\Actions\Fiscal\BuildNfcePayload;
use App\Actions\Fiscal\ReserveFiscalNumber;
use App\Enums\CardBrand as CardBrandEnum;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalPaymentMethod;
use App\Enums\PersonType;
use App\Exceptions\FiscalDocumentException;
use App\Models\Category;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\Fiscal\NfcePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Montagem do payload da NFC-e (RN-FIS-05..10): totais fecham centavo a centavo,
 * pizza de sabores é um item, frete é rateado, brinde fica fora, comprador só com dados válidos.
 */
class BuildNfcePayloadTest extends TestCase
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

    private function build(Sale $sale): NfcePayload
    {
        $settings = $this->nfceSettings($this->tenant);
        $document = app(ReserveFiscalNumber::class)($sale, $this->tenant, $settings, FiscalDocumentDecision::Manual);

        return app(BuildNfcePayload::class)($document, $sale, $settings);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function itemsCents(array $body): int
    {
        return (int) round(collect($body['items'])->sum(fn (array $item): float => $item['totalAmount'] + $item['othersAmount']) * 100);
    }

    public function test_builds_the_header_and_a_simple_item(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Calabresa', 40)]]);
        $sale = $this->finalizeNfceSale([$order], $this->pix);

        $body = $this->build($sale)->body;

        $this->assertSame(1, $body['serie']);
        $this->assertSame(1, $body['number']);
        $this->assertSame('Venda de mercadoria', $body['operationNature']);
        $this->assertSame('Outgoing', $body['operationType']);
        $this->assertSame('Internal_Operation', $body['destination']);
        $this->assertSame('Normal', $body['purposeType']);
        $this->assertSame('FinalConsumer', $body['consumerType']);
        $this->assertSame('Presence', $body['presenceType']);
        $this->assertSame($sale->finalized_at->toJSON(), $body['operationOn']);
        $this->assertCount(1, $body['items']);

        $item = $body['items'][0];
        $this->assertSame('CALABRESA', $item['description']);
        $this->assertSame('19059090', $item['ncm']);
        $this->assertSame(5102, $item['cfop']);
        $this->assertSame('UN', $item['unit']);
        $this->assertEquals(40, $item['unitAmount']);
        $this->assertEquals(40, $item['totalAmount']);
        $this->assertEquals(0, $item['othersAmount']);
        $this->assertEquals(0, $item['discountAmount']);
        $this->assertSame('SEM GTIN', $item['codeGTIN']);
        $this->assertSame(['icms' => ['origin' => '0', 'csosn' => '102', 'baseTax' => 0, 'amount' => 0, 'rate' => 0]], $item['tax']);
        $this->assertSame([['method' => 'InstantPayment', 'amount' => 40.0]], $body['payment'][0]['paymentDetail']);
        $this->assertEquals(0, $body['payment'][0]['payback']);
    }

    public function test_a_flavored_pizza_is_a_single_ascii_item_with_the_first_flavors_fiscal_data(): void
    {
        $category = Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas Salgadas', 'ncm' => '19059090', 'cfop' => '5102', 'csosn' => '102', 'origin_code' => '0', 'commercial_unit' => 'UN']);
        $a = $this->makeProduct($this->tenant, 'Calabresa Acebolada', 50, ['category_id' => $category->id]);
        $b = $this->makeProduct($this->tenant, 'Frango c/ Catupiry', 50, ['category_id' => $category->id]);
        $c = $this->makeProduct($this->tenant, 'Quatro Queijos', 50, ['category_id' => $category->id]);
        $base = $this->makeFiscalProduct($this->tenant, 'Pizza Grande', 50);

        $order = $this->makeNfceOrder($this->tenant, [['product' => $base, 'unit_price' => 50, 'flavors' => [$a->id, $b->id, $c->id]]]);
        $body = $this->build($this->finalizeNfceSale([$order], $this->pix))->body;

        $this->assertCount(1, $body['items']);
        $this->assertSame('PIZZAS SALGADAS CALABRESA ACEBOLADA / FRANGO C/ CATUPIRY / QUATRO QUEIJOS', $body['items'][0]['description']);
        $this->assertSame((string) $a->id, $body['items'][0]['code']);
        $this->assertSame('19059090', $body['items'][0]['ncm']);
    }

    public function test_descriptions_are_ascii_and_at_most_120_characters(): void
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza Açaí & Café "Especial" '.str_repeat('Longa ', 30), 10);
        $body = $this->build($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $product]])], $this->pix))->body;

        $description = $body['items'][0]['description'];
        $this->assertLessThanOrEqual(120, strlen($description));
        $this->assertMatchesRegularExpression('/^[A-Z0-9 \/.,\-]+$/', $description);
        $this->assertStringStartsWith('PIZZA ACAI CAFE ESPECIAL LONGA', $description);
    }

    public function test_addons_go_to_others_amount_multiplied_by_quantity(): void
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $order = $this->makeNfceOrder($this->tenant, [['product' => $product, 'quantity' => 2, 'addons_total' => 5]]);
        $sale = $this->finalizeNfceSale([$order], $this->pix);

        $body = $this->build($sale)->body;

        $this->assertEquals(40, $body['items'][0]['unitAmount']);
        $this->assertEquals(80, $body['items'][0]['totalAmount']);
        $this->assertEquals(10, $body['items'][0]['othersAmount']);
        $this->assertSame(9000, $this->itemsCents($body));
        $this->assertSame(9000, (int) round($sale->total * 100));
    }

    public function test_the_delivery_fee_is_split_equally_in_cents_across_the_lines(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [
            ['product' => $this->makeFiscalProduct($this->tenant, 'A', 10)],
            ['product' => $this->makeFiscalProduct($this->tenant, 'B', 20)],
            ['product' => $this->makeFiscalProduct($this->tenant, 'C', 30)],
        ], deliveryFee: 4);
        $sale = $this->finalizeNfceSale([$order], $this->pix);

        $body = $this->build($sale)->body;

        $this->assertEqualsCanonicalizing([1.34, 1.33, 1.33], array_column($body['items'], 'othersAmount'));
        $this->assertSame(6400, $this->itemsCents($body));
    }

    public function test_a_standalone_item_becomes_a_line(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $soda = $this->makeFiscalProduct($this->tenant, 'Refrigerante', 8);

        $sale = $this->finalizeNfceSale([$order], $this->pix, standalone: [['product_id' => $soda->id, 'quantity' => 2]]);
        $body = $this->build($sale)->body;

        $this->assertSame(['PIZZA', 'REFRIGERANTE'], array_column($body['items'], 'description'));
        $this->assertSame(5600, $this->itemsCents($body));
    }

    public function test_a_gift_is_not_an_item_of_the_note(): void
    {
        $gift = $this->makeFiscalProduct($this->tenant, 'Refri Brinde', 8);
        $order = $this->makeNfceOrder($this->tenant, [[
            'product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40),
            'gifts' => [['gift_product_id' => $gift->id, 'quantity' => 1, 'accepted' => true]],
        ]]);

        $body = $this->build($this->finalizeNfceSale([$order], $this->pix))->body;

        $this->assertSame(['PIZZA'], array_column($body['items'], 'description'));
        $this->assertSame(4000, $this->itemsCents($body));
    }

    public function test_mixed_payment_with_change_closes_to_the_cent(): void
    {
        $cash = $this->makeFiscalPaymentOption($this->tenant, 'Dinheiro', FiscalPaymentMethod::Cash, isCash: true);
        $credit = $this->makeFiscalPaymentOption($this->tenant, 'Crédito', FiscalPaymentMethod::CreditCard);
        $brand = $this->makeCardBrand($this->tenant, CardBrandEnum::AmericanExpress, '33444555000166');

        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 70)]]);
        $sale = $this->finalizeNfceSale([$order], $cash, [
            ['payment_option_id' => $cash->id, 'amount' => 50],
            ['payment_option_id' => $credit->id, 'amount' => 30, 'card_brand_id' => $brand->id, 'authorization_code' => 'AUT123'],
        ]);

        $payment = $this->build($sale)->body['payment'][0];

        $this->assertEquals(10, $payment['payback']);
        $this->assertSame('cash', $payment['paymentDetail'][0]['method']);
        $this->assertEquals(50, $payment['paymentDetail'][0]['amount']);
        $this->assertSame('creditCard', $payment['paymentDetail'][1]['method']);
        $this->assertSame(
            ['federalTaxNumber' => 33444555000166, 'flag' => 'americanExpress', 'authorization' => 'AUT123'],
            $payment['paymentDetail'][1]['card'],
        );
        $this->assertArrayNotHasKey('integrationPaymentType', $payment['paymentDetail'][1]['card']);
        $this->assertArrayNotHasKey('card', $payment['paymentDetail'][0]);
    }

    public function test_payment_method_comes_from_the_fiscal_code_never_from_the_name(): void
    {
        $odd = $this->makeFiscalPaymentOption($this->tenant, 'Cartão de Crédito', FiscalPaymentMethod::MealVoucher);
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);

        $detail = $this->build($this->finalizeNfceSale([$order], $odd))->body['payment'][0]['paymentDetail'][0];

        $this->assertSame('mealVouchers', $detail['method']);
        $this->assertArrayNotHasKey('card', $detail);
    }

    public function test_a_complete_individual_buyer_is_identified(): void
    {
        $client = $this->makeNfceClient($this->tenant, ['email' => 'maria@example.com']);
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]], client: $client);

        $result = $this->build($this->finalizeNfceSale([$order], $this->pix));

        $this->assertNull($result->buyerOmissionReason);
        $this->assertSame('Maria da Silva', $result->body['buyer']['name']);
        $this->assertSame((int) self::VALID_CPF, $result->body['buyer']['federalTaxNumber']);
        $this->assertSame(2, $result->body['buyer']['type']);
        $this->assertSame('NonTaxPayer', $result->body['buyer']['stateTaxNumberIndicator']);
        $this->assertSame('maria@example.com', $result->body['buyer']['email']);
    }

    public function test_a_company_buyer_uses_the_cnpj_and_type_4(): void
    {
        $client = $this->makeNfceClient($this->tenant, ['name' => 'Empresa X', 'person_type' => PersonType::Company, 'cnpj' => '11222333000181', 'cpf' => null]);
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]], client: $client);

        $buyer = $this->build($this->finalizeNfceSale([$order], $this->pix))->body['buyer'];

        $this->assertSame(11222333000181, $buyer['federalTaxNumber']);
        $this->assertSame(4, $buyer['type']);
    }

    public function test_buyer_is_omitted_with_a_reason_when_the_data_is_incomplete(): void
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);

        $cases = [
            'sem cliente' => null,
            'sem CPF' => $this->makeNfceClient($this->tenant, ['cpf' => null]),
            'CPF inválido' => $this->makeNfceClient($this->tenant, ['cpf' => '11111111111']),
        ];

        foreach ($cases as $label => $client) {
            $order = $this->makeNfceOrder($this->tenant, [['product' => $product]], client: $client);
            $sale = $this->finalizeNfceSale([$order], $this->pix);
            FiscalDocument::query()->where('sale_id', $sale->id)->delete();

            $result = $this->build($sale);

            $this->assertArrayNotHasKey('buyer', $result->body, $label);
            $this->assertNotNull($result->buyerOmissionReason, $label);
        }
    }

    public function test_buyer_is_omitted_when_orders_belong_to_different_clients(): void
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 40);
        $one = $this->makeNfceOrder($this->tenant, [['product' => $product]], client: $this->makeNfceClient($this->tenant));
        $two = $this->makeNfceOrder($this->tenant, [['product' => $product]], client: $this->makeNfceClient($this->tenant, ['name' => 'João']));

        $result = $this->build($this->finalizeNfceSale([$one, $two], $this->pix));

        $this->assertArrayNotHasKey('buyer', $result->body);
        $this->assertStringContainsString('diferentes', $result->buyerOmissionReason);
    }

    public function test_csosn_with_icms_carries_total_tax_and_the_icms_block(): void
    {
        $this->nfceSettings($this->tenant)->update(['approximate_tax_percent' => 10]);
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza', 100, ['csosn' => '900', 'icms_rate' => '18']);
        $body = $this->build($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $product]])], $this->pix))->body;

        $tax = $body['items'][0]['tax'];
        $this->assertEquals(10, $tax['totalTax']);
        $this->assertSame('3', $tax['icms']['baseTaxModality']);
        $this->assertEquals(100, $tax['icms']['baseTax']);
        $this->assertEquals(18, $tax['icms']['amount']);
        $this->assertEquals(18, $tax['icms']['rate']);
        $this->assertSame('900', $tax['icms']['csosn']);
    }

    public function test_it_refuses_when_a_product_has_no_fiscal_data(): void
    {
        $product = $this->makeProduct($this->tenant, 'Sem NCM', 40);
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $product]])], $this->pix);

        $this->expectException(FiscalDocumentException::class);
        $this->expectExceptionMessage('sem dados fiscais');
        $this->build($sale);
    }

    public function test_it_refuses_when_the_payment_option_has_no_fiscal_code(): void
    {
        $plain = $this->makePaymentOption($this->tenant, 'Sem código');
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $plain);

        $this->expectException(FiscalDocumentException::class);
        $this->expectExceptionMessage('sem código fiscal');
        $this->build($sale);
    }

    public function test_it_refuses_when_the_items_do_not_add_up_to_the_sale_total(): void
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);
        $sale = $this->finalizeNfceSale([$order], $this->pix);
        Sale::query()->whereKey($sale->id)->update(['total' => 41]);

        $this->expectException(FiscalDocumentException::class);
        $this->expectExceptionMessage('não fecham');
        $this->build($sale->refresh());
    }

    public function test_the_body_never_carries_credentials(): void
    {
        $body = $this->build($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $this->pix))->body;

        $json = json_encode($body);
        $this->assertStringNotContainsString('chave-secreta-da-api', $json);
        $this->assertStringNotContainsString('segredo-do-webhook', $json);
        $this->assertStringNotContainsString('company-123', $json);
    }
}

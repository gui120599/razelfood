<?php

namespace Tests\Feature\Fiscal;

use App\Actions\Products\ReplicateProductsToCategory;
use App\Enums\FiscalPaymentMethod;
use App\Enums\PersonType;
use App\Filament\Tenant\Resources\Categories\Pages\CreateCategory;
use App\Filament\Tenant\Resources\Clients\Pages\CreateClient;
use App\Filament\Tenant\Resources\Clients\Pages\EditClient;
use App\Filament\Tenant\Resources\PaymentOptions\Pages\CreatePaymentOption;
use App\Filament\Tenant\Resources\Products\Pages\CreateProduct;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Client;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Campos fiscais nos cadastros do tenant: aparecem só com a feature
 * `nfe_emissao`, validam formato e gravam só dígitos.
 */
class FiscalFormsTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->forgetTenantContext();
        $this->fakeIbge();
        $this->useTenant($this->makeFiscalTenant('lazzopizza'));
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function useTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->actAsTenant($tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
    }

    private function category(array $attributes = []): Category
    {
        return Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', ...$attributes]);
    }

    // ---- produto ---------------------------------------------------------

    public function test_the_product_fiscal_section_exists_only_with_the_feature(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertFormFieldVisible('ncm')
            ->assertFormFieldVisible('gtin');

        $this->useTenant($this->makeFiscalTenant('semnfe', withNfe: false));

        Livewire::test(CreateProduct::class)
            ->assertFormFieldHidden('ncm')
            ->assertFormFieldHidden('cfop');
    }

    public function test_a_product_is_saved_with_masked_fiscal_data_as_plain_digits(): void
    {
        $category = $this->category();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40,
                'ncm' => '1905.90.90', 'cfop' => '5102', 'csosn' => '102', 'origin_code' => '0',
                'commercial_unit' => 'UN', 'cest' => '17.001.00', 'gtin' => '7891000100103',
                'icms_rate' => 18, 'pis_rate' => 1.65, 'cofins_rate' => 7.6,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('name', 'Calabresa')->first();
        $this->assertSame('19059090', $product->ncm);
        $this->assertSame('5102', $product->cfop);
        $this->assertSame('1700100', $product->cest);
        $this->assertSame('7891000100103', $product->gtin);
        $this->assertSame('18.00', $product->icms_rate);
        $this->assertSame('7.60', $product->cofins_rate);
    }

    public function test_invalid_fiscal_formats_are_refused(): void
    {
        $category = $this->category();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40,
                'ncm' => '123', 'cfop' => '6102', 'cest' => '12', 'gtin' => '7891000100104', 'icms_rate' => 120,
            ])
            ->call('create')
            ->assertHasFormErrors(['ncm', 'cfop', 'cest', 'gtin', 'icms_rate']);

        $this->assertSame(0, Product::count());
    }

    public function test_fiscal_fields_are_optional_on_the_product(): void
    {
        $category = $this->category();

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $category->id, 'name' => 'Água', 'price' => 5])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Product::where('name', 'Água')->value('ncm'));
    }

    // ---- categoria -------------------------------------------------------

    public function test_the_category_default_has_no_gtin_and_is_saved(): void
    {
        Livewire::test(CreateCategory::class)
            ->assertFormFieldVisible('ncm')
            ->assertFormFieldDoesNotExist('gtin')
            ->fillForm(['name' => 'Bebidas', 'ncm' => '2202.10.00', 'cfop' => '5102', 'csosn' => '500', 'origin_code' => '0', 'commercial_unit' => 'UN'])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::where('name', 'Bebidas')->first();
        $this->assertSame('22021000', $category->ncm);
        $this->assertSame('500', $category->csosn);
    }

    public function test_the_category_section_is_hidden_without_the_feature(): void
    {
        $this->useTenant($this->makeFiscalTenant('semnfe', withNfe: false));

        Livewire::test(CreateCategory::class)->assertFormFieldHidden('ncm');
    }

    // ---- ação em lote ----------------------------------------------------

    private function bulkFiscal(array $products, array $data): void
    {
        Livewire::test(ListProducts::class)
            ->selectTableRecords(collect($products)->pluck('id')->all())
            ->callAction(TestAction::make('setFiscalData')->table()->bulk(), $data)
            ->assertHasNoActionErrors();
    }

    public function test_the_bulk_action_applies_only_the_filled_fields(): void
    {
        $a = $this->makeProduct($this->tenant, 'Calabresa', 40, ['ncm' => '19059090', 'cfop' => '5101']);
        $b = $this->makeProduct($this->tenant, 'Mussarela', 42);

        $this->bulkFiscal([$a, $b], ['cfop' => '5102', 'csosn' => '102', 'origin_code' => '0', 'commercial_unit' => 'UN']);

        $this->assertSame('5102', $a->fresh()->cfop);
        $this->assertSame('19059090', $a->fresh()->ncm, 'Campo vazio na ação mantém o que o produto já tinha.');
        $this->assertSame('102', $b->fresh()->csosn);
        $this->assertNull($b->fresh()->ncm);
    }

    public function test_the_bulk_action_with_nothing_filled_changes_nothing_and_warns(): void
    {
        $product = $this->makeProduct($this->tenant, 'Calabresa', 40, ['ncm' => '19059090']);

        $this->bulkFiscal([$product], []);

        $this->assertSame('19059090', $product->fresh()->ncm);
        Notification::assertNotified('Nada para aplicar');
    }

    public function test_the_bulk_action_is_hidden_without_the_feature(): void
    {
        $this->useTenant($this->makeFiscalTenant('semnfe', withNfe: false));
        $product = $this->makeProduct($this->tenant, 'Calabresa', 40);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->assertActionHidden(TestAction::make('setFiscalData')->table()->bulk());
    }

    // ---- forma de pagamento ---------------------------------------------

    public function test_a_payment_option_saves_its_fiscal_data(): void
    {
        Livewire::test(CreatePaymentOption::class)
            ->assertFormFieldVisible('fiscal_payment_code')
            ->fillForm([
                'name' => 'Cartão de crédito', 'fiscal_payment_code' => 'credit_card',
                'auto_issue_fiscal_document' => true, 'requires_card_brand' => true, 'requires_authorization_code' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $option = PaymentOption::where('name', 'Cartão de crédito')->first();
        $this->assertSame(FiscalPaymentMethod::CreditCard, $option->fiscal_payment_code);
        $this->assertTrue($option->auto_issue_fiscal_document);
        $this->assertTrue($option->requires_card_brand);
        $this->assertTrue($option->requires_authorization_code);
    }

    public function test_the_payment_option_fiscal_section_is_hidden_without_the_feature_and_the_flags_default_off(): void
    {
        $this->useTenant($this->makeFiscalTenant('semnfe', withNfe: false));

        Livewire::test(CreatePaymentOption::class)
            ->assertFormFieldHidden('fiscal_payment_code')
            ->fillForm(['name' => 'Dinheiro'])
            ->call('create')
            ->assertHasNoFormErrors();

        $option = PaymentOption::where('name', 'Dinheiro')->first();
        $this->assertNull($option->fiscal_payment_code);
        $this->assertFalse($option->requires_card_brand);
    }

    public function test_an_unknown_fiscal_payment_code_is_refused(): void
    {
        Livewire::test(CreatePaymentOption::class)
            ->fillForm(['name' => 'Pix', 'fiscal_payment_code' => 'withoutPayment'])
            ->call('create')
            ->assertHasFormErrors(['fiscal_payment_code']);
    }

    // ---- cliente ---------------------------------------------------------

    public function test_a_company_client_needs_a_valid_cnpj_and_keeps_digits_only(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Empresa X', 'phone' => '11988887777', 'person_type' => 'company'])
            ->call('create')
            ->assertHasFormErrors(['cnpj' => 'required']);

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Empresa X', 'phone' => '11988887777', 'person_type' => 'company', 'cnpj' => '11.222.333/0001-99'])
            ->call('create')
            ->assertHasFormErrors(['cnpj']);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Empresa X', 'phone' => '11988887777', 'person_type' => 'company',
                'cnpj' => '11.222.333/0001-81', 'state_registration' => 'isento', 'email' => 'fiscal@empresax.com.br',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::where('name', 'Empresa X')->first();
        $this->assertSame(PersonType::Company, $client->person_type);
        $this->assertSame('11222333000181', $client->cnpj);
        $this->assertSame('fiscal@empresax.com.br', $client->email);
    }

    public function test_an_individual_client_hides_cnpj_and_ie_and_validates_the_email(): void
    {
        Livewire::test(CreateClient::class)
            ->assertFormFieldHidden('cnpj')
            ->assertFormFieldHidden('state_registration')
            ->fillForm(['name' => 'Maria', 'phone' => '11977776666', 'email' => 'nao-e-email'])
            ->call('create')
            ->assertHasFormErrors(['email' => 'email']);
    }

    public function test_the_client_fiscal_section_is_hidden_without_the_feature_and_the_type_defaults_to_individual(): void
    {
        $this->useTenant($this->makeFiscalTenant('semnfe', withNfe: false));

        Livewire::test(CreateClient::class)
            ->assertFormFieldHidden('person_type')
            ->fillForm(['name' => 'Maria', 'phone' => '11977776666'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(PersonType::Individual, Client::where('name', 'Maria')->first()->person_type);
    }

    public function test_adulterated_ncm_and_gtin_never_reach_the_database(): void
    {
        $category = $this->category();

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40, 'ncm' => '1234-5678', 'gtin' => 'ABC7891000100'])
            ->call('create')
            ->assertHasFormErrors(['ncm', 'gtin']);

        $this->assertSame(0, Product::count());

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40, 'gtin' => '7891-000-100103'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('7891000100103', Product::where('name', 'Calabresa')->value('gtin'), 'O GTIN é gravado só com dígitos.');
    }

    public function test_replicating_a_product_to_another_category_copies_the_fiscal_data_but_not_the_gtin(): void
    {
        $source = $this->makeProduct($this->tenant, 'Calabresa', 40, $this->completeProductFiscalData() + ['gtin' => '7891000100103']);
        $target = $this->category(['name' => 'Promoções']);

        app(ReplicateProductsToCategory::class)(collect([$source]), $target);

        $copy = Product::where('category_id', $target->id)->first();
        $this->assertSame('19059090', $copy->ncm);
        $this->assertSame('5102', $copy->cfop);
        $this->assertNull($copy->gtin, 'Código de barras é por produto: a cópia não herda.');
    }

    public function test_switching_a_client_to_individual_clears_the_company_data(): void
    {
        $client = Client::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Empresa X', 'phone' => '11988887777',
            'person_type' => 'company', 'cnpj' => '11222333000181', 'state_registration' => '123456789',
        ]);

        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['person_type' => 'individual'])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame(PersonType::Individual, $client->person_type);
        $this->assertNull($client->cnpj);
        $this->assertNull($client->state_registration);
    }

    public function test_the_client_state_registration_is_normalized_like_the_fiscal_settings(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Empresa Y', 'phone' => '11966665555', 'person_type' => 'company', 'cnpj' => '11222333000181', 'state_registration' => '110.042.490.114'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('110042490114', Client::where('name', 'Empresa Y')->value('state_registration'));
    }
}

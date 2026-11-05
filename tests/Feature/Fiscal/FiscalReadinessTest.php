<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalPaymentMethod;
use App\Enums\TaxRegime;
use App\Models\Category;
use App\Models\PaymentOption;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Support\Fiscal\FiscalIssue;
use App\Support\Fiscal\FiscalReadiness;
use App\Support\Fiscal\FiscalReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Checklist de prontidão (doc migracao-nfeio §5.1): o que falta no tenant,
 * nos produtos e nas formas de pagamento. Consulta sempre pelo tenant
 * informado, sem depender do tenant "atual".
 */
class FiscalReadinessTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
    }

    private function readiness(Tenant $tenant): FiscalReadinessReport
    {
        return app(FiscalReadiness::class)->forTenant($tenant);
    }

    /**
     * @return list<string>
     */
    private function messages(Tenant $tenant, string $section): array
    {
        return array_map(fn (FiscalIssue $issue): string => $issue->message, $this->readiness($tenant)->issuesIn($section));
    }

    private function readyTenant(string $slug): Tenant
    {
        $tenant = $this->makeFiscalTenant($slug);
        $this->completeFiscalSettings($tenant);
        $this->makeProduct($tenant, 'Calabresa', 40, $this->completeProductFiscalData());
        PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Dinheiro', 'is_cash' => true, 'fiscal_payment_code' => FiscalPaymentMethod::Cash]);

        return $tenant;
    }

    public function test_a_fully_configured_tenant_is_ready(): void
    {
        $tenant = $this->readyTenant('lazzopizza');

        $report = $this->readiness($tenant);

        $this->assertTrue($report->ready(), json_encode($report->issues));
        $this->assertSame([], $report->issues);
    }

    public function test_an_empty_tenant_lists_cnpj_address_and_missing_fiscal_data(): void
    {
        $tenant = $this->makeFiscalTenant('vazio', withAddress: false);

        $messages = $this->messages($tenant, FiscalIssue::SECTION_TENANT);

        $this->assertFalse($this->readiness($tenant)->ready());
        $this->assertContains('Informe o CNPJ do estabelecimento (em Estabelecimento).', $messages);
        $this->assertContains('Complete o endereço do estabelecimento: CEP, logradouro, número, bairro, cidade, UF.', $messages);
        $this->assertContains('Preencha os dados fiscais do estabelecimento.', $messages);
    }

    public function test_an_invalid_cnpj_is_reported(): void
    {
        $tenant = $this->makeFiscalTenant('lazzopizza');
        $tenant->update(['cnpj' => '11222333000199']);
        $this->completeFiscalSettings($tenant);

        $this->assertContains('O CNPJ do estabelecimento é inválido.', $this->messages($tenant, FiscalIssue::SECTION_TENANT));
    }

    public function test_incomplete_fiscal_settings_list_each_missing_item(): void
    {
        $tenant = $this->makeFiscalTenant('lazzopizza');
        $this->completeFiscalSettings($tenant, [
            'legal_name' => null, 'state_registration' => null, 'city_ibge_code' => null,
            'nfeio_company_id' => null, 'nfeio_api_key' => null,
        ]);

        $messages = $this->messages($tenant, FiscalIssue::SECTION_TENANT);

        foreach (['razão social', 'Inscrição Estadual', 'código IBGE', 'companyId', 'chave de API'] as $fragment) {
            $this->assertNotEmpty(array_filter($messages, fn (string $m): bool => str_contains($m, $fragment)), "Faltou avisar: {$fragment}");
        }
    }

    public function test_an_unsupported_regime_blocks_readiness_with_the_clear_message(): void
    {
        $tenant = $this->makeFiscalTenant('lazzopizza');
        $this->completeFiscalSettings($tenant, ['tax_regime' => 'lucro_presumido']);

        $this->assertContains(TaxRegime::unsupportedMessage(), $this->messages($tenant, FiscalIssue::SECTION_TENANT));
    }

    public function test_products_without_fiscal_data_are_counted_and_sampled(): void
    {
        $tenant = $this->readyTenant('lazzopizza');

        foreach (range(1, 12) as $n) {
            $this->makeProduct($tenant, "Produto {$n}", 10);
        }

        $issue = $this->readiness($tenant)->issuesIn(FiscalIssue::SECTION_PRODUCTS)[0];

        $this->assertSame(12, $issue->count);
        $this->assertSame('12 produtos sem dados fiscais completos.', $issue->message);
        $this->assertCount(10, $issue->items, 'A amostra é limitada.');
        $this->assertStringContainsString('faltam: NCM, CFOP, CSOSN, Origem, Unidade', $issue->items[0]);
    }

    public function test_a_category_default_makes_its_products_complete(): void
    {
        $tenant = $this->readyTenant('lazzopizza');
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Bebidas', ...$this->completeProductFiscalData()]);
        $product = $this->makeProduct($tenant, 'Refrigerante', 8, ['category_id' => $category->id]);

        $this->assertTrue($this->readiness($tenant)->ready());

        $category->update(['ncm' => null]);

        $issue = $this->readiness($tenant)->issuesIn(FiscalIssue::SECTION_PRODUCTS)[0];
        $this->assertStringContainsString("{$product->name} (faltam: NCM)", $issue->items[0]);
    }

    public function test_deleted_products_do_not_count(): void
    {
        $tenant = $this->readyTenant('lazzopizza');
        $this->makeProduct($tenant, 'Descontinuado', 5)->delete();

        $this->assertTrue($this->readiness($tenant)->ready());
    }

    public function test_payment_options_without_a_fiscal_code_are_listed(): void
    {
        $tenant = $this->readyTenant('lazzopizza');
        PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Pix']);
        PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Cartão']);

        $issue = $this->readiness($tenant)->issuesIn(FiscalIssue::SECTION_PAYMENTS)[0];

        $this->assertSame(2, $issue->count);
        $this->assertSame(['Cartão', 'Pix'], $issue->items);
    }

    public function test_readiness_never_leaks_data_between_tenants(): void
    {
        $ready = $this->readyTenant('lazzopizza');
        $broken = $this->makeFiscalTenant('emporiodapizza');
        $this->makeProduct($broken, 'Sem dados', 10);
        PaymentOption::create(['tenant_id' => $broken->id, 'name' => 'Pix']);

        $this->assertTrue($this->readiness($ready)->ready(), 'Os problemas do outro tenant não aparecem aqui.');
        $this->assertNotEmpty($this->readiness($broken)->issuesIn(FiscalIssue::SECTION_PRODUCTS));
        $this->assertSame('Sem dados (faltam: NCM, CFOP, CSOSN, Origem, Unidade)', $this->readiness($broken)->issuesIn(FiscalIssue::SECTION_PRODUCTS)[0]->items[0]);
    }

    public function test_inheritance_from_the_category_works_even_when_another_tenant_is_the_current_one(): void
    {
        $tenant = $this->readyTenant('lazzopizza');
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Bebidas', ...$this->completeProductFiscalData()]);
        $this->makeProduct($tenant, 'Refrigerante', 8, ['category_id' => $category->id]);

        $other = $this->makeFiscalTenant('emporiodapizza');
        $this->actAsTenant($other); // job/comando/painel central consultando outro tenant

        $this->assertTrue($this->readiness($tenant)->ready(), 'O escopo do tenant atual não pode esconder a categoria.');
    }

    public function test_the_uf_must_be_set_and_match_the_establishment_address(): void
    {
        $tenant = $this->readyTenant('lazzopizza');

        TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['state_uf' => 'RJ']);
        $this->assertContains('A UF dos dados fiscais (RJ) é diferente da UF do endereço (SP).', $this->messages($tenant, FiscalIssue::SECTION_TENANT));

        TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['state_uf' => null]);
        $this->assertContains('Selecione a UF do estabelecimento nos dados fiscais.', $this->messages($tenant, FiscalIssue::SECTION_TENANT));
    }

    public function test_a_stale_tenant_model_does_not_hide_edits_made_in_the_database(): void
    {
        $tenant = $this->readyTenant('lazzopizza');
        $stale = Tenant::find($tenant->id); // como o do cache `tenant:slug:*`

        Tenant::query()->whereKey($tenant->id)->update(['cnpj' => null]);

        $this->assertContains('Informe o CNPJ do estabelecimento (em Estabelecimento).', $this->messages($stale, FiscalIssue::SECTION_TENANT));
    }

    public function test_readiness_does_not_decrypt_the_credentials_just_to_check_they_exist(): void
    {
        $tenant = $this->readyTenant('lazzopizza');
        // Valor que NÃO decifra (ex.: APP_KEY trocada): a prontidão só confere presença.
        DB::table('tenant_fiscal_settings')->where('tenant_id', $tenant->id)->update(['nfeio_api_key' => 'nao-decifra']);

        $this->assertTrue($this->readiness($tenant)->ready());
        $this->assertTrue(TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()->hasCredentials());
    }
}

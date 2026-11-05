<?php

namespace Tests\Feature\Fiscal;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalPaymentMethod;
use App\Filament\Tenant\Pages\FiscalSettings;
use App\Models\PaymentOption;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Página "Dados fiscais" do painel do tenant: gate por feature + papel,
 * salvamento, regime só Simples Nacional, prontidão e habilitação da emissão.
 */
class FiscalSettingsPageTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->fakeIbge();
        $this->tenant = $this->makeFiscalTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function settings(): ?TenantFiscalSettings
    {
        return TenantFiscalSettings::query()->where('tenant_id', $this->tenant->id)->first();
    }

    private function makeEverythingReady(): void
    {
        $this->completeFiscalSettings($this->tenant);
        $this->makeProduct($this->tenant, 'Calabresa', 40, $this->completeProductFiscalData());
        PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Dinheiro', 'is_cash' => true, 'fiscal_payment_code' => FiscalPaymentMethod::Cash]);
    }

    public function test_only_the_admin_of_a_tenant_with_the_feature_reaches_the_page(): void
    {
        foreach (['Admin' => true, 'Gerente' => false, 'Caixa' => false, 'Atendente' => false, 'Entregador' => false] as $role => $allowed) {
            $this->actingAsRole($role);

            $this->assertSame($allowed, FiscalSettings::canAccess(), $role);
            $this->assertSame($allowed, FiscalSettings::shouldRegisterNavigation(), $role);
        }
    }

    public function test_without_the_feature_nobody_reaches_the_page_not_even_the_admin(): void
    {
        $plain = $this->makeFiscalTenant('semnfe', withNfe: false);
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $admin = User::factory()->create(['tenant_id' => $plain->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->assertFalse(FiscalSettings::canAccess());
        $this->assertFalse(FiscalSettings::shouldRegisterNavigation());

        Livewire::test(FiscalSettings::class)->assertForbidden();
    }

    public function test_the_admin_saves_the_fiscal_identification(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm([
                'legal_name' => 'Pizzaria Lazzo LTDA',
                'state_registration' => '123.456.789',
                'nfce_series' => 2,
                'approximate_tax_percent' => '13.45',
                'nfeio_company_id' => 'comp-1',
                'nfeio_api_key' => 'K-secreta',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Dados fiscais salvos');

        $settings = $this->settings();
        $this->assertSame('Pizzaria Lazzo LTDA', $settings->legal_name);
        $this->assertSame('123456789', $settings->state_registration);
        $this->assertSame(2, $settings->nfce_series);
        $this->assertSame('13.45', $settings->approximate_tax_percent);
        $this->assertSame('K-secreta', $settings->nfeio_api_key);
        $this->assertSame('simples_nacional', $settings->tax_regime->value);
        $this->assertSame($this->tenant->id, $settings->tenant_id);
    }

    public function test_an_unsupported_tax_regime_is_refused_with_a_clear_message(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['tax_regime' => 'lucro_presumido'])
            ->call('save')
            ->assertHasFormErrors(['tax_regime']);

        $this->assertNull($this->settings());
    }

    public function test_invalid_state_registration_and_series_are_refused(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['state_registration' => 'ABC', 'nfce_series' => 0])
            ->call('save')
            ->assertHasFormErrors(['state_registration', 'nfce_series']);
    }

    public function test_the_city_is_suggested_from_the_establishment_address(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->assertFormSet(['state_uf' => 'SP', 'city_ibge_code' => 3550308]);
    }

    public function test_the_readiness_panel_lists_what_is_missing(): void
    {
        $this->actingAsRole('Admin');
        $this->makeProduct($this->tenant, 'Calabresa', 40);

        Livewire::test(FiscalSettings::class)
            ->assertSee('Falta completar o cadastro')
            ->assertSee('Preencha os dados fiscais do estabelecimento.')
            ->assertSee('1 produto sem dados fiscais completos.')
            ->assertSee('Calabresa (faltam: NCM, CFOP, CSOSN, Origem, Unidade)');
    }

    public function test_the_readiness_panel_confirms_when_everything_is_ready(): void
    {
        $this->makeEverythingReady();
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)->assertSee('Tudo pronto');
    }

    public function test_the_emission_cannot_be_enabled_while_the_checklist_is_incomplete(): void
    {
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['legal_name' => 'Pizzaria Lazzo LTDA', 'emission_enabled' => true])
            ->call('save')
            ->assertNotified('Dados salvos, mas a emissão não foi habilitada')
            ->assertSet('data.emission_enabled', false);

        $this->assertNull($this->settings()->enabled_at);
    }

    public function test_the_emission_is_enabled_with_a_complete_checklist_and_can_be_turned_off(): void
    {
        $this->makeEverythingReady();
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['emission_enabled' => true])
            ->call('save')
            ->assertNotified('Dados fiscais salvos')
            ->assertSet('data.emission_enabled', true);

        $enabledAt = $this->settings()->enabled_at;
        $this->assertNotNull($enabledAt);

        Livewire::test(FiscalSettings::class)
            ->assertFormSet(['emission_enabled' => true])
            ->call('save');

        $this->assertEquals($enabledAt, $this->settings()->fresh()->enabled_at, 'Salvar de novo não muda a data de habilitação.');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['emission_enabled' => false])
            ->call('save');

        $this->assertNull($this->settings()->fresh()->enabled_at);
    }

    public function test_a_gerente_gets_403_when_calling_the_page_directly(): void
    {
        $this->makeEverythingReady();
        $this->actingAsRole('Gerente');

        Livewire::test(FiscalSettings::class)->assertForbidden();
    }
}

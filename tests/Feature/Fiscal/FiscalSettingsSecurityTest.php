<?php

namespace Tests\Feature\Fiscal;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Tenant\Pages\FiscalSettings;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * MT-04 / critério 9 do doc: credenciais criptografadas no banco, fora do
 * cache de tenant, fora de qualquer serialização e nunca devolvidas ao
 * navegador; em branco = manter o valor atual, nos dois painéis.
 */
class FiscalSettingsSecurityTest extends TestCase
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

    private function raw(string $column): ?string
    {
        return DB::table('tenant_fiscal_settings')->where('tenant_id', $this->tenant->id)->value($column);
    }

    public function test_credentials_are_encrypted_in_the_database(): void
    {
        $this->completeFiscalSettings($this->tenant);

        foreach (['nfeio_api_key' => 'chave-secreta-da-api', 'nfeio_webhook_secret' => 'segredo-do-webhook'] as $column => $plain) {
            $this->assertNotNull($this->raw($column));
            $this->assertStringNotContainsString($plain, $this->raw($column), "{$column} ficou em texto puro.");
            $this->assertSame($plain, TenantFiscalSettings::query()->where('tenant_id', $this->tenant->id)->value($column));
        }
    }

    public function test_the_secrets_stay_out_of_serialization(): void
    {
        $settings = $this->completeFiscalSettings($this->tenant);

        $this->assertArrayNotHasKey('nfeio_api_key', $settings->toArray());
        $this->assertArrayNotHasKey('nfeio_webhook_secret', $settings->toArray());
        $this->assertStringNotContainsString('chave-secreta-da-api', $settings->toJson());
        $this->assertStringNotContainsString('segredo-do-webhook', $settings->toJson());
    }

    public function test_the_real_tenant_cache_entry_never_contains_the_secrets(): void
    {
        $this->completeFiscalSettings($this->tenant);
        Cache::forget('tenant:slug:lazzopizza');
        config(['tenancy.cache.enabled' => true]);
        $this->forgetTenantContext();

        $this->get('/lazzopizza')->assertOk(); // ResolveTenantFromPath grava o Tenant em cache

        $cached = Cache::get('tenant:slug:lazzopizza');
        $this->assertInstanceOf(Tenant::class, $cached, 'O tenant deveria ter sido cacheado pela requisição.');

        $serialized = serialize($cached);
        $this->assertStringNotContainsString('chave-secreta-da-api', $serialized);
        $this->assertStringNotContainsString('segredo-do-webhook', $serialized);
        $this->assertStringNotContainsString('nfeio_api_key', $serialized);
    }

    public function test_tenant_page_never_sends_the_saved_secrets_back_to_the_browser(): void
    {
        $this->completeFiscalSettings($this->tenant);
        $this->actingAsRole('Admin');

        $component = Livewire::test(FiscalSettings::class)
            ->assertFormSet(['nfeio_api_key' => null, 'nfeio_webhook_secret' => null, 'nfeio_company_id' => 'company-123']);

        $this->assertStringNotContainsString('chave-secreta-da-api', json_encode($component->get('data')));
        $this->assertStringNotContainsString('segredo-do-webhook', json_encode($component->get('data')));
    }

    public function test_blank_credentials_keep_the_current_ones_and_filled_ones_replace_them(): void
    {
        $this->completeFiscalSettings($this->tenant);
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['legal_name' => 'Outro nome LTDA'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = TenantFiscalSettings::query()->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('Outro nome LTDA', $settings->legal_name);
        $this->assertSame('chave-secreta-da-api', $settings->nfeio_api_key, 'Em branco mantém a chave salva.');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['nfeio_api_key' => 'chave-nova'])
            ->call('save');

        $settings->refresh();
        $this->assertSame('chave-nova', $settings->nfeio_api_key);
        $this->assertSame('segredo-do-webhook', $settings->nfeio_webhook_secret, 'O outro segredo não foi tocado.');
    }

    public function test_central_panel_saves_the_fiscal_section_through_the_relationship(): void
    {
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
            ->fillForm(['fiscalSettings' => [
                'legal_name' => 'Pizzaria Central LTDA',
                'state_registration' => 'isento',
                'nfeio_company_id' => 'company-9',
                'nfeio_api_key' => 'chave-central',
                'nfeio_webhook_secret' => 'segredo-central',
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();

        $this->assertNotNull($settings);
        $this->assertSame('Pizzaria Central LTDA', $settings->legal_name);
        $this->assertSame('ISENTO', $settings->state_registration);
        $this->assertSame('chave-central', $settings->nfeio_api_key);
        $this->assertStringNotContainsString('chave-central', $this->raw('nfeio_api_key'));
        $this->assertSame('simples_nacional', $this->raw('tax_regime'), 'O default do banco vale quando o campo não vem.');
        $this->assertSame('test', $this->raw('environment'));
        $this->assertSame(1, (int) $this->raw('nfce_series'));
    }

    public function test_central_panel_keeps_the_secrets_when_the_fields_are_left_blank(): void
    {
        $this->completeFiscalSettings($this->tenant);
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
            ->assertFormSet(['fiscalSettings.nfeio_api_key' => null, 'fiscalSettings.legal_name' => 'Pizzaria Teste LTDA'])
            ->fillForm(['fiscalSettings' => ['legal_name' => 'Renomeada']])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('Renomeada', $settings->legal_name);
        $this->assertSame('chave-secreta-da-api', $settings->nfeio_api_key);
    }

    public function test_central_panel_rejects_an_unsupported_tax_regime(): void
    {
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
            ->fillForm(['fiscalSettings' => ['tax_regime' => 'lucro_real']])
            ->call('save')
            ->assertHasFormErrors(['fiscalSettings.tax_regime']);

        $this->assertDatabaseMissing('tenant_fiscal_settings', ['tenant_id' => $this->tenant->id, 'tax_regime' => 'lucro_real']);
    }

    public function test_saving_an_existing_tenant_without_fiscal_data_is_not_blocked(): void
    {
        // Tenants antigos não têm linha fiscal: salvar o cadastro central não pode exigir nada novo.
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
            ->fillForm(['name' => 'Novo nome comercial'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Novo nome comercial', $this->tenant->fresh()->name);
    }

    public function test_the_typed_secrets_do_not_stay_in_the_component_state_after_saving_on_the_tenant_page(): void
    {
        $this->actingAsRole('Admin');

        $component = Livewire::test(FiscalSettings::class)
            ->fillForm(['nfeio_api_key' => 'digitada-agora', 'nfeio_webhook_secret' => 'outro-segredo-digitado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($component->get('data.nfeio_api_key'));
        $this->assertNull($component->get('data.nfeio_webhook_secret'));
        $this->assertStringNotContainsString('digitada-agora', json_encode($component->get('data')));
        $this->assertSame('digitada-agora', TenantFiscalSettings::query()->where('tenant_id', $this->tenant->id)->value('nfeio_api_key'));
    }

    public function test_the_typed_secrets_do_not_stay_in_the_component_state_after_saving_on_the_central_panel(): void
    {
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        $component = Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
            ->fillForm(['fiscalSettings' => ['nfeio_api_key' => 'digitada-no-central', 'nfeio_company_id' => 'company-c']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($component->get('data.fiscalSettings.nfeio_api_key'));
        $this->assertStringNotContainsString('digitada-no-central', json_encode($component->get('data')));
    }

    public function test_the_same_company_id_cannot_be_used_by_two_tenants(): void
    {
        $other = $this->makeFiscalTenant('emporiodapizza');
        $this->completeFiscalSettings($other, ['nfeio_company_id' => 'empresa-do-outro']);
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['nfeio_company_id' => 'empresa-do-outro'])
            ->call('save')
            ->assertHasFormErrors(['nfeio_company_id']);

        $this->assertNull(TenantFiscalSettings::query()->where('tenant_id', $this->tenant->id)->value('nfeio_company_id'));
    }

    public function test_a_tenant_can_resave_its_own_company_id_and_the_database_enforces_uniqueness_too(): void
    {
        $this->completeFiscalSettings($this->tenant, ['nfeio_company_id' => 'minha-empresa']);
        $this->actingAsRole('Admin');

        Livewire::test(FiscalSettings::class)
            ->fillForm(['legal_name' => 'Renomeada'])
            ->call('save')
            ->assertHasNoFormErrors();

        $other = $this->makeFiscalTenant('emporiodapizza');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->completeFiscalSettings($other, ['nfeio_company_id' => 'minha-empresa']);
    }

    public function test_the_central_panel_also_refuses_a_company_id_used_by_another_tenant(): void
    {
        $other = $this->makeFiscalTenant('emporiodapizza');
        $this->completeFiscalSettings($other, ['nfeio_company_id' => 'empresa-do-outro']);
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Livewire::test(EditTenant::class, ['record' => $this->tenant->id])
            ->fillForm(['fiscalSettings' => ['nfeio_company_id' => 'empresa-do-outro']])
            ->call('save')
            ->assertHasFormErrors(['fiscalSettings.nfeio_company_id']);
    }

    public function test_creating_a_tenant_in_the_central_panel_keeps_a_single_fiscal_row_with_defaults(): void
    {
        $this->actingAsPlatformAdmin();
        $this->forgetTenantContext();
        Filament::setCurrentPanel(Filament::getPanel('central'));
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Pizzaria Nova', 'slug' => 'pizzaria-nova', 'plan_id' => $plan->id, 'whatsapp_number' => '5511999999999',
                'admin_name' => 'Admin', 'admin_email' => 'admin@pizzaria-nova.com.br', 'admin_password' => 'password123',
                'fiscalSettings' => ['legal_name' => 'Pizzaria Nova LTDA', 'nfeio_api_key' => 'chave-nova-tenant'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('slug', 'pizzaria-nova')->firstOrFail();
        $rows = TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Pizzaria Nova LTDA', $rows->first()->legal_name);
        $this->assertSame('chave-nova-tenant', $rows->first()->nfeio_api_key);
        $this->assertSame('simples_nacional', $rows->first()->tax_regime->value);
        $this->assertSame('test', $rows->first()->environment->value);
        $this->assertSame(1, $rows->first()->nfce_series);
    }
}

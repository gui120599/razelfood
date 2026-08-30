<?php

namespace Tests\Feature\Central;

use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Models\DeliveryOption;
use App\Models\PaymentOption;
use App\Models\Plan;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre o fluxo completo de CreateTenant::afterCreate() via Livewire — hoje
 * os demais testes do painel central (PlanFeatureManagementTest) só usam
 * Tenant::create() direto como fixture, nenhum exercitava esse hook.
 */
class TenantCreationSeedsDefaultOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Http::fake([
            'servicodados.ibge.gov.br/*/estados?*' => Http::response([
                ['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo'],
            ]),
            'servicodados.ibge.gov.br/*/estados/SP/municipios' => Http::response([
                ['id' => 3550308, 'nome' => 'São Paulo'],
            ]),
        ]);
    }

    public function test_creating_a_tenant_seeds_default_delivery_and_payment_options(): void
    {
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Pizzaria Teste',
                'slug' => 'pizzaria-teste',
                'plan_id' => $plan->id,
                'whatsapp_number' => '5511999999999',
                'admin_name' => 'Admin Teste',
                'admin_email' => 'admin@pizzaria-teste.com.br',
                'admin_password' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('slug', 'pizzaria-teste')->firstOrFail();

        $this->assertSame(3, DeliveryOption::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(4, PaymentOption::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('delivery_options', ['tenant_id' => $tenant->id, 'name' => 'Entregar', 'requires_address' => true]);
        $this->assertDatabaseHas('payment_options', ['tenant_id' => $tenant->id, 'name' => 'Dinheiro', 'is_cash' => true]);
    }

    public function test_creating_a_tenant_persists_cnpj_and_address(): void
    {
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Pizzaria Endereço',
                'slug' => 'pizzaria-endereco',
                'plan_id' => $plan->id,
                'whatsapp_number' => '5511999999999',
                'cnpj' => '11.222.333/0001-81',
                'zip_code' => '01001-000',
                'street' => 'Praça da Sé',
                'number' => '100',
                'neighborhood' => 'Sé',
                'state' => 'SP',
                'admin_name' => 'Admin Teste',
                'admin_email' => 'admin@pizzaria-endereco.com.br',
                'admin_password' => 'password123',
            ])
            ->fillForm(['city' => 'São Paulo'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'pizzaria-endereco',
            'cnpj' => '11222333000181',
            'zip_code' => '01001000',
            'street' => 'Praça da Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);
    }

    public function test_creating_a_tenant_rejects_an_invalid_cnpj(): void
    {
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Pizzaria CNPJ Ruim',
                'slug' => 'pizzaria-cnpj-ruim',
                'plan_id' => $plan->id,
                'whatsapp_number' => '5511999999999',
                'cnpj' => '11.222.333/0001-99',
                'admin_name' => 'Admin Teste',
                'admin_email' => 'admin@pizzaria-cnpj-ruim.com.br',
                'admin_password' => 'password123',
            ])
            ->call('create')
            ->assertHasFormErrors(['cnpj']);
    }
}

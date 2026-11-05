<?php

namespace Tests\Feature\Fiscal;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Filament\Tenant\Pages\FiscalSettings;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use App\Support\Fiscal\FiscalReadiness;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Critério global §16.2 para a Fase 1.1: com dois tenants, dados fiscais,
 * credenciais e produtos de um nunca são lidos nem alterados pelo outro.
 */
class FiscalIsolationTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->fakeIbge();
        $this->a = $this->makeFiscalTenant('lazzopizza');
        $this->b = $this->makeFiscalTenant('emporiodapizza');
        $this->completeFiscalSettings($this->a, ['nfeio_company_id' => 'empresa-A', 'nfeio_api_key' => 'chave-A']);
        $this->completeFiscalSettings($this->b, ['nfeio_company_id' => 'empresa-B', 'nfeio_api_key' => 'chave-B']);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function actAs(Tenant $tenant, string $role = 'Admin'): void
    {
        $this->actAsTenant($tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_each_tenant_only_sees_its_own_fiscal_settings(): void
    {
        $this->actAsTenant($this->a);
        $this->assertSame(['empresa-A'], TenantFiscalSettings::pluck('nfeio_company_id')->all());

        $this->actAsTenant($this->b);
        $this->assertSame(['empresa-B'], TenantFiscalSettings::pluck('nfeio_company_id')->all());
        $this->assertSame('chave-B', TenantFiscalSettings::first()->nfeio_api_key);
    }

    public function test_saving_the_page_of_one_tenant_never_touches_the_other(): void
    {
        $this->actAs($this->a);

        Livewire::test(FiscalSettings::class)
            ->assertFormSet(['nfeio_company_id' => 'empresa-A'])
            ->fillForm(['legal_name' => 'Só da A', 'nfeio_company_id' => 'empresa-A2', 'nfeio_api_key' => 'chave-A2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $b = TenantFiscalSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->first();
        $this->assertSame('Pizzaria Teste LTDA', $b->legal_name);
        $this->assertSame('empresa-B', $b->nfeio_company_id);
        $this->assertSame('chave-B', $b->nfeio_api_key);
    }

    public function test_the_bulk_fiscal_action_only_touches_products_of_the_current_tenant(): void
    {
        Gate::before(fn () => true); // o foco aqui é o escopo de tenant, não a permissão de Resource

        $productA = $this->makeProduct($this->a, 'Calabresa', 40);
        $otherA = $this->makeProduct($this->a, 'Mussarela', 42);
        $productB = $this->makeProduct($this->b, 'Portuguesa', 45);

        $this->actAs($this->a);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$productA, $otherA])
            ->assertCanNotSeeTableRecords([$productB])
            ->selectTableRecords([$productA->id, $otherA->id])
            ->callAction(TestAction::make('setFiscalData')->table()->bulk(), ['ncm' => '19059090', 'cfop' => '5102'])
            ->assertHasNoActionErrors();

        $this->assertSame('19059090', $productA->fresh()->ncm);
        $this->assertSame('19059090', $otherA->fresh()->ncm);
        $this->assertNull(Product::withoutGlobalScopes()->find($productB->id)->ncm, 'O produto do outro tenant não foi tocado.');
    }

    public function test_readiness_is_computed_per_tenant_regardless_of_the_current_one(): void
    {
        $this->makeProduct($this->a, 'Sem dados', 10);
        $this->makeProduct($this->b, 'Completo', 10, $this->completeProductFiscalData());

        $this->actAsTenant($this->b); // contexto "errado" de propósito

        $reportA = app(FiscalReadiness::class)->forTenant($this->a);
        $reportB = app(FiscalReadiness::class)->forTenant($this->b);

        $this->assertNotEmpty($reportA->issuesIn('products'));
        $this->assertEmpty($reportB->issuesIn('products'));
    }

    public function test_the_page_only_shows_the_company_of_the_current_tenant(): void
    {
        $this->actAs($this->b);

        // O formulário mostra a empresa do tenant B — nunca a da A.
        Livewire::test(FiscalSettings::class)
            ->assertFormSet(['nfeio_company_id' => 'empresa-B'])
            ->assertDontSee('empresa-A');
    }
}

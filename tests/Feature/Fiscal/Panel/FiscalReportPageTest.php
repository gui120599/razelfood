<?php

namespace Tests\Feature\Fiscal\Panel;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Filament\Tenant\Pages\FiscalReport;
use App\Models\Category;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Página "Relatório fiscal (NFC-e)", CSV e impressão: gate por feature + papel, mesmos números
 * nas três saídas, isolamento entre tenants.
 */
class FiscalReportPageTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
        $this->fakeNfeIo();

        $this->issueNfce($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Calabresa', 40)]], 4)], $this->pix), now());
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

    private function exportUrl(string $route, array $extra = []): string
    {
        return route($route, $extra + ['start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString(), 'groupings' => ['ncm', 'payment']]);
    }

    public function test_only_admin_and_manager_reach_the_report(): void
    {
        foreach (['Admin' => true, 'Gerente' => true, 'Caixa' => false, 'Atendente' => false, 'Entregador' => false] as $role => $allowed) {
            $this->actingAsRole($role);

            $this->assertSame($allowed, FiscalReport::canAccess(), $role);
            $this->assertSame($allowed, FiscalReport::shouldRegisterNavigation(), $role);
        }
    }

    public function test_a_tenant_without_the_feature_never_reaches_the_report(): void
    {
        $plain = $this->makeSaleTenant('semnfe');
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $user = User::factory()->create(['tenant_id' => $plain->id]);
        $user->assignRole('Admin');
        $this->actingAs($user);

        $this->assertFalse(FiscalReport::canAccess());
        $this->get($this->exportUrl('reports.fiscal.csv'))->assertForbidden();
        $this->get($this->exportUrl('reports.fiscal.print'))->assertForbidden();
    }

    public function test_the_page_shows_the_summary_the_selected_groupings_and_the_notes(): void
    {
        $this->actingAsRole('Gerente');

        Livewire::test(FiscalReport::class)
            ->assertOk()
            ->assertSee('Notas autorizadas')
            ->assertSee('Total das notas')
            ->assertSee('R$ 44,00')
            ->assertSee('Por NCM')
            ->assertSee('19059090')
            ->assertSee('Por categoria de produto')
            ->assertDontSee('Por produto')
            ->set('groupings', ['product'])
            ->assertSee('Por produto')
            ->assertSee('Calabresa')
            ->assertDontSee('Por NCM')
            ->assertSee('Notas do período (1)')
            ->assertSee('Números reservados e não usados (0)')
            ->assertSee('Nenhum número pendente');
    }

    public function test_changing_the_period_changes_the_numbers_and_shows_unused_numbers(): void
    {
        $this->actingAsRole('Admin');
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Esfiha', 10)]])], $this->pix);
        $document = $this->makeFiscalDocumentFor($sale, FiscalDocumentStatus::FailedToSend);
        FiscalDocument::query()->whereKey($document->id)->update(['created_at' => now()->subDays(2), 'error_message' => 'Falha de conexão']);

        Livewire::test(FiscalReport::class)
            ->assertSee('Números reservados e não usados (1)')
            ->assertSee('Falha no envio: Falha de conexão')
            ->set('startDate', now()->subYear()->startOfMonth()->toDateString())
            ->set('endDate', now()->subYear()->endOfMonth()->toDateString())
            ->assertSee('Notas do período (0)')
            ->assertSee('Sem dados no período');
    }

    public function test_the_csv_download_has_bom_semicolons_and_the_same_numbers(): void
    {
        $this->actingAsRole('Gerente');

        $response = $this->get($this->exportUrl('reports.fiscal.csv'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $content = str_replace('"', '', $response->streamedContent());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Notas autorizadas;1', $content);
        $this->assertStringContainsString('Total das notas;44,00', $content);
        $this->assertStringContainsString('Por NCM', $content);
        $this->assertStringContainsString('Por forma de pagamento', $content);
        $this->assertStringContainsString('Pix;;;;;44,00', $content);
    }

    public function test_the_print_view_renders_the_same_numbers_with_the_tenant_header(): void
    {
        $this->actingAsRole('Gerente');

        $this->get($this->exportUrl('reports.fiscal.print'))
            ->assertOk()
            ->assertSee('Relatório fiscal (NFC-e)')
            ->assertSee('Lazzopizza')
            ->assertSee('Total das notas')
            ->assertSee('R$ 44,00', false)
            ->assertSee('Por NCM')
            ->assertSee('Por forma de pagamento')
            ->assertDontSee('Por produto');
    }

    public function test_exports_require_login_and_the_permission(): void
    {
        $this->get($this->exportUrl('reports.fiscal.csv'))->assertForbidden();

        foreach (['Caixa', 'Atendente', 'Entregador'] as $role) {
            $this->actingAsRole($role);
            $this->get($this->exportUrl('reports.fiscal.csv'))->assertForbidden();
            $this->get($this->exportUrl('reports.fiscal.print'))->assertForbidden();
        }
    }

    public function test_exports_never_leak_another_tenants_notes(): void
    {
        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');
        $otherPix = $this->makeFiscalPaymentOption($other, 'Pix', FiscalPaymentMethod::InstantPayment);
        $this->issueNfce($this->finalizeNfceSale([$this->makeNfceOrder($other, [['product' => $this->makeFiscalProduct($other, 'Esfiha Secreta', 99)]])], $otherPix), now());
        $this->actAsTenant($this->tenant);
        $this->actingAsRole('Admin');

        $csv = str_replace('"', '', $this->get($this->exportUrl('reports.fiscal.csv', ['groupings' => ['product']]))->streamedContent());
        $print = $this->get($this->exportUrl('reports.fiscal.print', ['groupings' => ['product']]))->getContent();

        $this->assertStringNotContainsStringIgnoringCase('Esfiha Secreta', $csv);
        $this->assertStringNotContainsStringIgnoringCase('Esfiha Secreta', $print);
        $this->assertStringContainsString('Calabresa', $csv);
    }

    public function test_invalid_query_parameters_fall_back_instead_of_failing(): void
    {
        $this->actingAsRole('Admin');

        $this->get(route('reports.fiscal.csv', ['start' => 'nada', 'end' => ['x'], 'groupings' => 'ncm']))->assertOk();
        $this->get(route('reports.fiscal.print', ['start' => 'nada', 'groupings' => ['invalida']]))->assertOk();
    }

    public function test_the_csv_neutralizes_formula_injection_and_the_page_warns_about_notes_without_items(): void
    {
        Category::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => '=HYPERLINK("http://x")']);
        $evil = Category::withoutGlobalScopes()->where('name', '=HYPERLINK("http://x")')->first();
        $pix = $this->makeFiscalPaymentOption($this->tenant, '+cmd|calc', FiscalPaymentMethod::InstantPayment);
        $this->issueNfce($this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza X', 10, ['category_id' => $evil->id])]])], $pix), now());
        $legacy = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Antiga', 5)]])], $this->pix);
        $this->makeFiscalDocumentFor($legacy, FiscalDocumentStatus::Issued)->update(['authorized_at' => now()]);
        $this->actingAsRole('Admin');

        $csv = $this->get($this->exportUrl('reports.fiscal.csv', ['groupings' => ['category', 'payment']]))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd|calc", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->assertStringContainsString('Atenção: 1 nota(s)', $csv);

        Livewire::test(FiscalReport::class)->assertSee('1 nota(s) do período não têm o detalhe de itens');
        $this->get($this->exportUrl('reports.fiscal.print'))->assertSee('1 nota(s) do período não têm o detalhe', false);
    }
}

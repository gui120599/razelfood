<?php

namespace Tests\Feature\Fiscal\Nfce;

use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\FinalizeSale;
use App\Actions\Sales\StartSale;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\CardBrand as CardBrandEnum;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Filament\Tenant\Pages\Sales\NewSale;
use App\Filament\Tenant\Resources\CardBrands\CardBrandResource;
use App\Filament\Tenant\Resources\CardBrands\Pages\CreateCardBrand;
use App\Filament\Tenant\Resources\CardBrands\Pages\EditCardBrand;
use App\Filament\Tenant\Resources\CardBrands\Pages\ListCardBrands;
use App\Filament\Tenant\Resources\Sales\Pages\ListSales;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Models\CardBrand;
use App\Models\FiscalDocument;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Telas da Fase 1.2: documento fiscal na venda (emitir/reenviar/atualizar/DANFE), coluna NFC-e,
 * cadastro de bandeiras e bandeira no fechamento de conta. Papéis reais (SeedDefaultTenantRoles).
 */
class NfceUiTest extends TestCase
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
        Http::preventStrayRequests();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
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

    private function sale(): Sale
    {
        return $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), 40)]])], $this->pix);
    }

    // ---- Tela da venda ---------------------------------------------------

    public function test_a_cashier_issues_the_nfce_from_the_sale_screen(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionVisible('issueFiscalDocument')
            ->callAction('issueFiscalDocument')
            ->assertNotified('NFC-e enviada: aguardando a autorização');

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocumentStatus::Processing, $document->status);
        $this->assertSame(auth()->id(), $document->user_id);
        $this->assertSame('manual', $document->decision->value);
    }

    public function test_the_issue_action_is_hidden_for_roles_without_the_permission(): void
    {
        $this->actingAsRole('Atendente');
        $sale = $this->sale();

        Livewire::test(ViewSale::class, ['record' => $sale->id])->assertForbidden();
    }

    public function test_the_issue_action_is_hidden_without_emit_permission_even_if_the_user_can_view_sales(): void
    {
        $user = $this->actingAsRole('Caixa');
        $user->roles->first()->revokePermissionTo('emit_fiscal_document');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $sale = $this->sale();

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionHidden('issueFiscalDocument')
            ->assertActionHidden('refreshFiscalDocument');
    }

    public function test_a_blocked_emission_shows_the_reason_and_creates_nothing(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        $this->nfceSettings($this->tenant)->update(['legal_name' => null]);
        Http::fake();

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->callAction('issueFiscalDocument')
            ->assertNotified('Não foi possível emitir a NFC-e');

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_a_failed_send_offers_resend_and_reuses_the_number(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['message' => 'fora do ar'], 503)
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);

        $page = Livewire::test(ViewSale::class, ['record' => $sale->id]);
        $page->callAction('issueFiscalDocument')->assertNotified('A NFC-e não foi enviada');

        $this->assertSame(FiscalDocumentStatus::FailedToSend, FiscalDocument::query()->sole()->status);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionExists('issueFiscalDocument', fn ($action): bool => $action->getLabel() === 'Reenviar NFC-e')
            ->callAction('issueFiscalDocument');

        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(1, FiscalDocument::query()->sole()->number);
    }

    public function test_the_section_shows_the_document_and_polls_only_while_processing(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)
            ->push(['id' => 'nfe-1', 'status' => 'Issued'])]);
        Livewire::test(ViewSale::class, ['record' => $sale->id])->callAction('issueFiscalDocument');

        $processing = Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertSee('Documento fiscal (NFC-e)')
            ->assertSee('Processando')
            ->assertSeeHtml('wire:poll.5s="pollFiscalDocument"');

        $processing->call('pollFiscalDocument')->assertSee('Emitida')->assertDontSeeHtml('wire:poll.5s="pollFiscalDocument"');

        $this->assertSame(FiscalDocumentStatus::Issued, FiscalDocument::query()->sole()->status);
    }

    public function test_refresh_status_action_updates_the_document(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)
            ->push(['status' => 'Issued'])]);
        $page = Livewire::test(ViewSale::class, ['record' => $sale->id])->callAction('issueFiscalDocument');

        $page->callAction('refreshFiscalDocument')->assertNotified('Status: Emitida');

        $this->assertSame(FiscalDocumentStatus::Issued, FiscalDocument::query()->sole()->status);
    }

    public function test_the_danfe_action_fetches_the_pdf_url_on_demand(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Issued'])
            ->push(['uri' => 'https://files.example/danfe.pdf'])]);
        $page = Livewire::test(ViewSale::class, ['record' => $sale->id]);
        $page->assertActionHidden('printDanfe')->callAction('issueFiscalDocument');

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertActionVisible('printDanfe')
            ->callAction('printDanfe')
            ->assertHasNoActionErrors();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/consumerinvoices/nfe-1/pdf'));
    }

    public function test_the_fiscal_section_and_actions_do_not_exist_without_the_feature(): void
    {
        $plain = $this->makeSaleTenant('semnfe');
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $user = User::factory()->create(['tenant_id' => $plain->id]);
        $user->assignRole('Caixa');
        $this->actingAs($user);
        $cash = $this->makePaymentOption($plain, 'Dinheiro', isCash: true);
        $sale = app(FinalizeSale::class)([$this->makeSaleOrder($plain)->id], [], [['payment_option_id' => $cash->id, 'amount' => 40]]);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertDontSee('Documento fiscal (NFC-e)')
            ->assertActionHidden('issueFiscalDocument')
            ->assertActionHidden('refreshFiscalDocument');

        Livewire::test(ListSales::class)->assertTableColumnHidden('currentFiscalDocument.status');
    }

    public function test_the_sales_table_shows_the_nfce_column(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Issued'])]);
        Livewire::test(ViewSale::class, ['record' => $sale->id])->callAction('issueFiscalDocument');

        Livewire::test(ListSales::class)
            ->assertCanSeeTableRecords([$sale])
            ->assertTableColumnVisible('currentFiscalDocument.status')
            ->assertSee('Emitida');
    }

    // ---- Bandeiras -------------------------------------------------------

    public function test_the_admin_manages_card_brands_and_the_cnpj_is_validated(): void
    {
        $this->actingAsRole('Admin');
        Gate::before(fn () => true);
        $this->assertTrue(CardBrandResource::canAccess());

        Livewire::test(CreateCardBrand::class)
            ->fillForm(['brand' => 'visa', 'name' => 'Visa Stone', 'acquirer_cnpj' => '11111111111111'])
            ->call('create')
            ->assertHasFormErrors(['acquirer_cnpj']);

        Livewire::test(CreateCardBrand::class)
            ->fillForm(['brand' => 'visa', 'name' => 'Visa Stone', 'acquirer_cnpj' => '11.222.333/0001-81'])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = CardBrand::query()->where('name', 'Visa Stone')->sole();
        $this->assertSame('11222333000181', $brand->acquirer_cnpj);
        $this->assertSame($this->tenant->id, $brand->tenant_id);

        Livewire::test(CreateCardBrand::class)
            ->fillForm(['brand' => 'visa', 'name' => 'Visa Stone'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        Livewire::test(EditCardBrand::class, ['record' => $brand->id])
            ->fillForm(['name' => 'Visa Cielo'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Visa Cielo', $brand->fresh()->name);
    }

    public function test_the_card_brand_list_only_shows_the_tenants_own_brands(): void
    {
        $this->actingAsRole('Admin');
        Gate::before(fn () => true);
        $own = $this->makeCardBrand($this->tenant);

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'company-outra']);
        $foreign = $this->makeCardBrand($other);
        $this->actAsTenant($this->tenant);

        Livewire::test(ListCardBrands::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_card_brands_are_not_reachable_without_the_feature(): void
    {
        $plain = $this->makeSaleTenant('semnfe');
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $user = User::factory()->create(['tenant_id' => $plain->id]);
        $user->assignRole('Admin');
        $this->actingAs($user);

        $this->assertFalse(CardBrandResource::canAccess());
    }

    // ---- Fechamento de conta --------------------------------------------

    private function draftFor(Order $order): Sale
    {
        return app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);
    }

    public function test_the_new_sale_uses_the_registered_card_brand_and_requires_it_when_the_option_demands(): void
    {
        Queue::fake();
        $this->actingAsRole('Caixa');
        $credit = $this->makeFiscalPaymentOption($this->tenant, 'Crédito', FiscalPaymentMethod::CreditCard);
        $credit->update(['requires_card_brand' => true]);
        $brand = $this->makeCardBrand($this->tenant, CardBrandEnum::Mastercard);
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]]);

        $component = Livewire::test(NewSale::class, ['sale' => $this->draftFor($order)->id])
            ->set('paymentOptionId', $credit->id)
            ->set('receivedAmount', 40.0)
            ->call('registerPayment')
            ->assertHasErrors('receivedAmount');

        $this->assertSame(0, SalePayment::query()->count());

        $component->set('cardBrandId', $brand->id)->call('registerPayment')->assertHasNoErrors()->call('finalizeSale')->assertHasNoErrors();

        $payment = SalePayment::query()->sole();
        $this->assertSame($brand->id, $payment->card_brand_id);
        $this->assertSame('Mastercard', $payment->card_brand);
    }

    public function test_the_poll_method_is_gated_by_permission_and_never_calls_the_provider_without_it(): void
    {
        $user = $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)
            ->push(['id' => 'nfe-1', 'status' => 'Issued'])]);
        Livewire::test(ViewSale::class, ['record' => $sale->id])->callAction('issueFiscalDocument');

        $user->roles->first()->revokePermissionTo('emit_fiscal_document');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        Http::assertSentCount(1);

        Livewire::test(ViewSale::class, ['record' => $sale->id])->call('pollFiscalDocument');

        Http::assertSentCount(1);
        $this->assertSame(FiscalDocumentStatus::Processing, FiscalDocument::query()->sole()->status);
    }

    public function test_polling_stops_after_the_polling_window(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        Livewire::test(ViewSale::class, ['record' => $sale->id])->callAction('issueFiscalDocument');

        Livewire::test(ViewSale::class, ['record' => $sale->id])->assertSeeHtml('wire:poll.5s="pollFiscalDocument"');

        $this->travel(16)->minutes();
        Livewire::test(ViewSale::class, ['record' => $sale->id])->assertDontSeeHtml('wire:poll.5s="pollFiscalDocument"');
    }

    public function test_a_non_https_danfe_address_is_refused(): void
    {
        $this->actingAsRole('Caixa');
        $sale = $this->sale();
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Issued'])
            ->push(['uri' => 'javascript:alert(1)'])]);
        Livewire::test(ViewSale::class, ['record' => $sale->id])->callAction('issueFiscalDocument');

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->callAction('printDanfe')
            ->assertNotified('A NFE.io devolveu um endereço de DANFE inválido');
    }

    public function test_a_card_brand_name_cannot_be_reused_even_after_the_old_one_was_deleted(): void
    {
        $this->actingAsRole('Admin');
        Gate::before(fn () => true);
        $this->makeCardBrand($this->tenant)->delete();

        Livewire::test(CreateCardBrand::class)
            ->fillForm(['brand' => 'visa', 'name' => 'Visa'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }
}

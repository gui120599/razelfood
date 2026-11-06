<?php

namespace Tests\Feature\Sales;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Filament\Tenant\Pages\Sales\NewSale;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Modal de NFC-e da Nova Venda: ao finalizar com forma de emissão automática a tela acompanha a
 * nota (processando → emitida/erro) sem sair; sem emissão automática volta direto para a tela vazia.
 */
class NewSaleFiscalModalTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $auto;

    private PaymentOption $manual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
        $this->auto = $this->makeFiscalPaymentOption($this->tenant, 'Pix automático', FiscalPaymentMethod::InstantPayment, auto: true);
        $this->manual = $this->makeFiscalPaymentOption($this->tenant, 'Pix manual', FiscalPaymentMethod::InstantPayment);

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('Caixa');
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function finalizeWith(PaymentOption $option): Testable
    {
        $product = $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), 40);

        return Livewire::test(NewSale::class)
            ->dispatch('product-selected', productId: $product->id)
            ->set('paymentOptionId', $option->id)
            ->set('receivedAmount', 40.0)
            ->call('registerPayment')
            ->call('finalizeSale');
    }

    public function test_a_sale_without_automatic_issue_goes_straight_back_to_an_empty_screen(): void
    {
        Bus::fake();

        $this->finalizeWith($this->manual)
            ->assertSet('fiscalModalOpen', false)
            ->assertRedirect(NewSale::getUrl());
    }

    public function test_a_sale_with_automatic_issue_opens_the_modal_in_processing(): void
    {
        Bus::fake();

        $this->finalizeWith($this->auto)
            ->assertSet('fiscalModalOpen', true)
            ->assertSet('fiscalStatus', 'processing')
            ->assertNoRedirect()
            ->assertSee('Emitindo NFC-e, aguarde...')
            ->assertSee('Continuar mesmo assim');
    }

    public function test_the_poll_keeps_processing_while_there_is_no_document_and_warns_when_it_takes_too_long(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);

        $component->call('checkFiscalStatus')->assertSet('fiscalStatus', 'processing')->assertSet('fiscalNotice', null);

        $component->set('fiscalPolls', 25)->call('checkFiscalStatus')->assertSet('fiscalStatus', 'processing')->assertSee('demorando');
    }

    public function test_the_poll_moves_to_issued_when_the_note_is_authorized_and_closing_returns_to_the_screen(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);
        $this->makeFiscalDocumentFor(Sale::sole(), FiscalDocumentStatus::Issued, 'nfe-ok');

        $component->call('checkFiscalStatus')
            ->assertSet('fiscalStatus', 'issued')
            ->assertSee('NFC-e emitida com sucesso!')
            ->assertSee('Ver DANFE')
            ->call('closeFiscalModal')
            ->assertSet('fiscalModalOpen', false)
            ->assertRedirect(NewSale::getUrl());
    }

    public function test_a_failed_note_shows_the_reason_and_the_retry_sends_it_again(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);
        $document = $this->makeFiscalDocumentFor(Sale::sole(), FiscalDocumentStatus::Failed, 'nfe-x');
        $document->update(['error_message' => 'NCM inválido no produto Pizza.']);

        $component->call('checkFiscalStatus')
            ->assertSet('fiscalStatus', 'error')
            ->assertSee('NCM inválido no produto Pizza.')
            ->assertSee('Tentar novamente')
            ->assertSee('Continuar sem NFC-e');

        $this->fakeNfeIo('Processing');
        $component->call('retryFiscalIssue')->assertSet('fiscalStatus', 'processing');

        $this->assertGreaterThanOrEqual(1, FiscalDocument::query()->count());
    }

    public function test_the_omitted_buyer_reason_is_shown_as_a_notice(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);
        $document = $this->makeFiscalDocumentFor(Sale::sole(), FiscalDocumentStatus::Issued, 'nfe-ok');
        $document->update(['buyer_omission_reason' => 'Venda sem pedidos: consumidor não identificado.']);

        $component->call('checkFiscalStatus')->assertSee('consumidor não identificado');
    }

    public function test_the_fiscal_actions_are_gated_by_permission(): void
    {
        $attendant = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $attendant->assignRole('Atendente');
        $this->actingAs($attendant);

        Livewire::test(NewSale::class)->assertForbidden();
    }

    public function test_an_ambiguous_send_warns_before_a_retry_to_avoid_a_duplicate_note(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);
        $document = $this->makeFiscalDocumentFor(Sale::sole(), FiscalDocumentStatus::FailedToSend);
        $document->update(['provider_id' => null, 'sent_at' => now()->subMinute(), 'error_message' => 'Tempo esgotado.']);

        $component->call('checkFiscalStatus')
            ->assertSet('fiscalStatus', 'error')
            ->assertSet('fiscalAmbiguous', true)
            ->assertSee('pode ter chegado à NFE.io')
            ->assertSeeHtml('wire:confirm');
    }

    public function test_states_that_never_finish_become_an_error_instead_of_spinning_forever(): void
    {
        Bus::fake();

        // Nenhuma nota nasceu (a emissão automática foi recusada por uma regra).
        $component = $this->finalizeWith($this->auto);
        $component->set('fiscalPolls', 41)->call('checkFiscalStatus')
            ->assertSet('fiscalStatus', 'error')
            ->assertSee('não foi emitida automaticamente');
    }

    public function test_a_reserved_or_stale_note_is_an_error_not_a_spinner(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);
        $document = $this->makeFiscalDocumentFor(Sale::sole(), FiscalDocumentStatus::Reserved);
        $document->update(['error_message' => 'O produto Pizza está sem dados fiscais.']);

        $component->call('checkFiscalStatus')->assertSet('fiscalStatus', 'error')->assertSee('sem dados fiscais');
    }

    public function test_a_processing_note_gives_up_after_the_poll_limit(): void
    {
        Bus::fake();
        $component = $this->finalizeWith($this->auto);
        $this->makeFiscalDocumentFor(Sale::sole(), FiscalDocumentStatus::Processing, 'nfe-lenta');
        $this->fakeNfeIo('Processing');

        $component->set('fiscalPolls', 41)->call('checkFiscalStatus')
            ->assertSet('fiscalStatus', 'error')
            ->assertSee('ainda não confirmou');
    }

    public function test_retrying_needs_the_emit_permission_not_just_manage_sales(): void
    {
        Bus::fake();
        $solo = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $solo->givePermissionTo('manage_sales');
        $this->actingAs($solo);
        $component = $this->finalizeWith($this->auto);

        $component->call('retryFiscalIssue')->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Fiscal\Panel;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Filament\Tenant\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Tenant\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Tenant\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Models\FiscalDocument;
use App\Models\FiscalWebhook;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Lista de documentos fiscais (Resource só leitura): acesso por feature + papel, filtros,
 * ações reaproveitadas da venda, detalhe com itens e webhooks, isolamento entre tenants.
 */
class FiscalDocumentResourceTest extends TestCase
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

    public function test_access_follows_the_role_and_the_feature(): void
    {
        foreach (['Admin' => true, 'Gerente' => true, 'Caixa' => true, 'Atendente' => false, 'Entregador' => false] as $role => $allowed) {
            $this->actingAsRole($role);

            $this->assertSame($allowed, FiscalDocumentResource::canAccess(), $role);
        }

        $plain = $this->makeSaleTenant('semnfe');
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $user = User::factory()->create(['tenant_id' => $plain->id]);
        $user->assignRole('Admin');
        $this->actingAs($user);

        $this->assertFalse(FiscalDocumentResource::canAccess());
    }

    public function test_the_resource_is_read_only(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Issued);
        $user = $this->actingAsRole('Admin');

        $this->assertFalse(FiscalDocumentResource::canCreate());
        $this->assertFalse($user->can('create', FiscalDocument::class));
        $this->assertFalse($user->can('update', $document));
        $this->assertFalse($user->can('delete', $document));
        $this->assertTrue($user->can('view', $document));
    }

    public function test_the_list_shows_only_the_tenants_own_documents(): void
    {
        $own = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Issued);
        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');
        $otherPix = $this->makeFiscalPaymentOption($other, 'Pix', FiscalPaymentMethod::InstantPayment);
        $foreign = $this->makeFiscalDocumentFor($this->finalizeNfceSale([$this->makeNfceOrder($other, [['product' => $this->makeFiscalProduct($other, 'Esfiha', 10)]])], $otherPix), FiscalDocumentStatus::Issued);
        $this->actAsTenant($this->tenant);
        $this->actingAsRole('Gerente');

        Livewire::test(ListFiscalDocuments::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ViewFiscalDocument::class, ['record' => $foreign->id]);
    }

    public function test_the_filters_narrow_by_status_environment_unused_numbers_and_period(): void
    {
        $issued = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Issued);
        $processing = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Processing);
        $unused = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::FailedToSend);
        FiscalDocument::query()->whereKey($unused->id)->update(['created_at' => now()->subDays(3)]);
        $production = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Issued);
        FiscalDocument::query()->whereKey($production->id)->update(['environment' => 'production']);
        $this->actingAsRole('Caixa');

        Livewire::test(ListFiscalDocuments::class)
            ->filterTable('status', 'processing')
            ->assertCanSeeTableRecords([$processing])
            ->assertCanNotSeeTableRecords([$issued, $unused, $production])
            ->removeTableFilter('status')
            ->filterTable('environment', 'production')
            ->assertCanSeeTableRecords([$production])
            ->assertCanNotSeeTableRecords([$issued, $processing, $unused])
            ->removeTableFilter('environment')
            ->filterTable('unused_numbers', true)
            ->assertCanSeeTableRecords([$unused])
            ->assertCanNotSeeTableRecords([$issued, $processing, $production])
            ->removeTableFilter('unused_numbers')
            ->filterTable('period', ['from' => now()->subDay()->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$issued, $processing, $production])
            ->assertCanNotSeeTableRecords([$unused]);
    }

    public function test_the_row_actions_reuse_the_sale_actions(): void
    {
        $processing = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Processing, 'nfe-p');
        $issued = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Issued, 'nfe-i');
        $failed = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::FailedToSend);
        $this->queueNfeIo(['id' => 'nfe-p', 'status' => 'Issued']);
        $this->actingAsRole('Gerente');

        $list = Livewire::test(ListFiscalDocuments::class);

        $list->assertActionVisible(TestAction::make('refreshFiscalDocument')->table($processing))
            ->assertActionVisible(TestAction::make('cancelFiscalDocument')->table($issued))
            ->assertActionHidden(TestAction::make('cancelFiscalDocument')->table($processing))
            ->assertActionVisible(TestAction::make('printDanfe')->table($issued))
            ->assertActionHidden(TestAction::make('printDanfe')->table($processing))
            ->assertActionVisible(TestAction::make('issueFiscalDocument')->table($failed))
            ->assertActionHidden(TestAction::make('issueFiscalDocument')->table($issued))
            ->callAction(TestAction::make('refreshFiscalDocument')->table($processing))
            ->assertNotified('Status: Emitida');

        $this->assertSame(FiscalDocumentStatus::Issued, $processing->fresh()->status);
    }

    public function test_an_old_cancelled_note_of_a_reissued_sale_has_no_actions(): void
    {
        $sale = $this->sale();
        $old = $this->makeFiscalDocumentFor($sale, FiscalDocumentStatus::Cancelled, 'nfe-velha');
        $current = $this->makeFiscalDocumentFor($sale, FiscalDocumentStatus::Issued, 'nfe-nova');
        $this->actingAsRole('Gerente');

        Livewire::test(ListFiscalDocuments::class)
            ->assertActionHidden(TestAction::make('refreshFiscalDocument')->table($old))
            ->assertActionHidden(TestAction::make('cancelFiscalDocument')->table($old))
            ->assertActionHidden(TestAction::make('issueFiscalDocument')->table($old))
            ->assertActionVisible(TestAction::make('cancelFiscalDocument')->table($current));
    }

    public function test_the_cashier_cannot_cancel_a_note_from_the_list(): void
    {
        $issued = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::Issued, 'nfe-i');
        $this->actingAsRole('Caixa');

        Livewire::test(ListFiscalDocuments::class)
            ->assertActionHidden(TestAction::make('cancelFiscalDocument')->table($issued));
    }

    public function test_the_detail_shows_the_saved_items_and_the_webhook_history(): void
    {
        $this->issueNfce($sale = $this->sale(), now());
        $document = FiscalDocument::query()->where('sale_id', $sale->id)->sole();
        FiscalWebhook::query()->create([
            'tenant_id' => $this->tenant->id, 'event' => 'status', 'provider_id' => $document->provider_id,
            'fiscal_document_id' => $document->id, 'payload' => ['status' => 'Issued'], 'signature_valid' => true,
            'outcome' => FiscalWebhook::OUTCOME_APPLIED, 'received_at' => now(), 'processed_at' => now(),
        ]);
        $this->actingAsRole('Gerente');

        Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
            ->assertOk()
            ->assertSee('Itens da nota')
            ->assertSee('PIZZA')
            ->assertSee('19059090')
            ->assertSee('Avisos da NFE.io')
            ->assertSee('Aplicado');
    }

    public function test_the_detail_hides_empty_sections(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale(), FiscalDocumentStatus::FailedToSend);
        $this->actingAsRole('Gerente');

        Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
            ->assertOk()
            ->assertDontSee('Itens da nota')
            ->assertDontSee('Avisos da NFE.io');
    }
}

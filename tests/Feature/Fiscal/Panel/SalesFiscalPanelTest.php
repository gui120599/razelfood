<?php

namespace Tests\Feature\Fiscal\Panel;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Filament\Tenant\Resources\Sales\Pages\ListSales;
use App\Filament\Tenant\Support\FiscalDocumentActions;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Tabela de Vendas com a NFC-e (paridade com o Pizzaria-App): filtro por situação da nota mais
 * recente e ações em lote (enviar / atualizar status) com teto, motivo de pulo e falha isolada.
 */
class SalesFiscalPanelTest extends TestCase
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

    private function sale(float $price = 40): Sale
    {
        return $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), $price)]])], $this->pix);
    }

    public function test_the_filter_uses_the_most_recent_note_of_each_sale(): void
    {
        $none = $this->sale();
        $issued = $this->sale();
        $this->makeFiscalDocumentFor($issued, FiscalDocumentStatus::Issued);
        $processing = $this->sale();
        $this->makeFiscalDocumentFor($processing, FiscalDocumentStatus::Processing);
        $cancelled = $this->sale();
        $this->makeFiscalDocumentFor($cancelled, FiscalDocumentStatus::Cancelled);
        $reissued = $this->sale();
        $this->makeFiscalDocumentFor($reissued, FiscalDocumentStatus::Cancelled);
        $this->makeFiscalDocumentFor($reissued, FiscalDocumentStatus::Issued);
        $this->actingAsRole('Gerente');

        Livewire::test(ListSales::class)
            ->filterTable('fiscal_status', 'none')
            ->assertCanSeeTableRecords([$none])
            ->assertCanNotSeeTableRecords([$issued, $processing, $cancelled, $reissued])
            ->filterTable('fiscal_status', 'issued')
            ->assertCanSeeTableRecords([$issued, $reissued])
            ->assertCanNotSeeTableRecords([$none, $processing, $cancelled])
            ->filterTable('fiscal_status', 'cancelled')
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$reissued, $issued])
            ->filterTable('fiscal_status', 'processing')
            ->assertCanSeeTableRecords([$processing])
            ->removeTableFilter('fiscal_status')
            ->assertCanSeeTableRecords([$none, $issued, $processing, $cancelled, $reissued]);
    }

    public function test_the_filter_and_the_batch_actions_do_not_exist_without_the_feature(): void
    {
        $plain = $this->makeSaleTenant('semnfe');
        $this->actAsTenant($plain);
        app(SeedDefaultTenantRoles::class)($plain);
        $user = User::factory()->create(['tenant_id' => $plain->id]);
        $user->assignRole('Caixa');
        $this->actingAs($user);

        Livewire::test(ListSales::class)
            ->assertTableFilterHidden('fiscal_status')
            ->assertActionHidden(TestAction::make('issueFiscalDocumentsInBatch')->table()->bulk())
            ->assertActionHidden(TestAction::make('refreshFiscalDocumentsInBatch')->table()->bulk());
    }

    public function test_batch_issue_sends_the_eligible_sales_and_skips_the_rest_with_the_reason(): void
    {
        $fresh = $this->sale();
        $failedBefore = $this->sale();
        $this->makeFiscalDocumentFor($failedBefore, FiscalDocumentStatus::FailedToSend);
        $alreadyIssued = $this->sale();
        $this->makeFiscalDocumentFor($alreadyIssued, FiscalDocumentStatus::Issued, 'nfe-ja');
        $cancelled = $this->sale();
        $cancelled->update(['status' => 'cancelled']);
        $this->actingAsRole('Caixa');

        Livewire::test(ListSales::class)
            ->selectTableRecords([$fresh->id, $failedBefore->id, $alreadyIssued->id, $cancelled->id])
            ->callAction(TestAction::make('issueFiscalDocumentsInBatch')->table()->bulk())
            ->assertNotified();

        $this->assertContains($fresh->currentFiscalDocument()->first()->status, [FiscalDocumentStatus::Issued, FiscalDocumentStatus::Processing]);
        $this->assertContains($failedBefore->currentFiscalDocument()->first()->status, [FiscalDocumentStatus::Issued, FiscalDocumentStatus::Processing]);
        $this->assertSame(1, FiscalDocument::query()->where('sale_id', $alreadyIssued->id)->count());
        $this->assertSame(0, FiscalDocument::query()->where('sale_id', $cancelled->id)->count());
    }

    public function test_a_failure_in_one_sale_does_not_stop_the_others_and_is_reported(): void
    {
        $first = $this->sale();
        $second = $this->sale();
        $this->queueNfeIo(['message' => 'CFOP inválido'], 400);
        $this->actingAsRole('Gerente');

        Livewire::test(ListSales::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('issueFiscalDocumentsInBatch')->table()->bulk())
            ->assertNotified();

        // A ordem do lote segue a tabela; o que importa é: uma falhou (e ficou registrada) e a outra foi enviada.
        $statuses = collect([$first, $second])->map(fn (Sale $sale): FiscalDocumentStatus => $sale->currentFiscalDocument()->first()->status);
        $this->assertCount(1, $statuses->filter(fn (FiscalDocumentStatus $status): bool => $status === FiscalDocumentStatus::FailedToSend));
        $this->assertCount(1, $statuses->filter(fn (FiscalDocumentStatus $status): bool => $status === FiscalDocumentStatus::Issued));
    }

    public function test_batch_issue_is_capped_per_round(): void
    {
        $sales = collect(range(1, FiscalDocumentActions::BATCH_LIMIT + 2))->map(fn (): Sale => $this->sale(10));
        $this->actingAsRole('Gerente');

        Livewire::test(ListSales::class)
            ->selectTableRecords($sales->pluck('id')->all())
            ->callAction(TestAction::make('issueFiscalDocumentsInBatch')->table()->bulk());

        $this->assertSame(FiscalDocumentActions::BATCH_LIMIT, FiscalDocument::query()->count());
        Http::assertSentCount(FiscalDocumentActions::BATCH_LIMIT);
    }

    public function test_batch_actions_require_the_emit_permission(): void
    {
        $sale = $this->sale();
        $user = $this->actingAsRole('Caixa');
        $user->roles->first()->revokePermissionTo('emit_fiscal_document');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        Livewire::test(ListSales::class)
            ->assertActionHidden(TestAction::make('issueFiscalDocumentsInBatch')->table()->bulk())
            ->assertActionHidden(TestAction::make('refreshFiscalDocumentsInBatch')->table()->bulk());

        $this->assertSame(0, FiscalDocument::query()->where('sale_id', $sale->id)->count());
    }

    public function test_batch_refresh_updates_notes_with_an_id_and_skips_the_others(): void
    {
        $processing = $this->sale();
        $this->makeFiscalDocumentFor($processing, FiscalDocumentStatus::Processing, 'nfe-p');
        $noNote = $this->sale();
        $cancelled = $this->sale();
        $this->makeFiscalDocumentFor($cancelled, FiscalDocumentStatus::Cancelled, 'nfe-c');
        $this->queueNfeIo(['id' => 'nfe-p', 'status' => 'Issued']);
        $this->actingAsRole('Caixa');

        Livewire::test(ListSales::class)
            ->selectTableRecords([$processing->id, $noNote->id, $cancelled->id])
            ->callAction(TestAction::make('refreshFiscalDocumentsInBatch')->table()->bulk())
            ->assertNotified();

        $this->assertSame(FiscalDocumentStatus::Issued, $processing->currentFiscalDocument()->first()->status);
        Http::assertSentCount(1);
    }

    public function test_batch_issue_never_makes_an_http_call_inside_a_database_transaction(): void
    {
        $sale = $this->sale();
        $before = DB::transactionLevel();
        $during = [];
        Http::fake(function () use (&$during) {
            $during[] = DB::transactionLevel();

            return Http::response(['id' => 'nfe-x', 'status' => 'Processing'], 202);
        });
        $this->actingAsRole('Gerente');

        Livewire::test(ListSales::class)
            ->selectTableRecords([$sale->id])
            ->callAction(TestAction::make('issueFiscalDocumentsInBatch')->table()->bulk());

        $this->assertSame([$before], $during);
    }
}

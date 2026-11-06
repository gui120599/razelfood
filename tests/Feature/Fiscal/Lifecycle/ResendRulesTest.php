<?php

namespace Tests\Feature\Fiscal\Lifecycle;

use App\Actions\Fiscal\IssueFiscalDocument;
use App\Actions\Sales\CancelSaleWithFiscalDocument;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\FiscalDocumentException;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Reenvio (RN-FIS-16): nota viva ou em andamento bloqueia outro envio; falha reaproveita o
 * número; nota cancelada libera um número novo.
 */
class ResendRulesTest extends TestCase
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
        $this->pix = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Pix', 'show_in_menu' => true, 'fiscal_payment_code' => FiscalPaymentMethod::InstantPayment]);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function sale(): Sale
    {
        return $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), 40)]])], $this->pix);
    }

    private function issue(Sale $sale): FiscalDocument
    {
        return app(IssueFiscalDocument::class)($sale, FiscalDocumentDecision::Manual);
    }

    public function test_every_live_or_in_progress_status_blocks_another_send(): void
    {
        Http::fake();

        foreach ([FiscalDocumentStatus::Issued, FiscalDocumentStatus::Processing, FiscalDocumentStatus::CancelRequested, FiscalDocumentStatus::CancelFailed] as $status) {
            $sale = $this->sale();
            $this->makeFiscalDocumentFor($sale, $status, 'nfe-'.$status->value);

            try {
                $this->issue($sale);
                $this->fail("Deveria bloquear com a nota {$status->value}.");
            } catch (FiscalDocumentException $exception) {
                $this->assertStringContainsString('já tem uma NFC-e', $exception->getMessage());
            }

            $this->assertSame(1, FiscalDocument::query()->where('sale_id', $sale->id)->count());
        }

        Http::assertNothingSent();
    }

    public function test_a_failed_or_rejected_note_is_resent_with_the_same_number(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'novo', 'status' => 'Processing'], 202)]);

        foreach ([FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Failed, FiscalDocumentStatus::Reserved] as $status) {
            $sale = $this->sale();
            $first = $this->makeFiscalDocumentFor($sale, $status, 'antigo-'.$status->value);
            FiscalDocument::query()->whereKey($first->id)->update(['provider_id' => null]);

            $again = $this->issue($sale);

            $this->assertSame($first->id, $again->id, $status->value);
            $this->assertSame($first->number, $again->number, $status->value);
            $this->assertSame(FiscalDocumentStatus::Processing, $again->status);
            FiscalDocument::query()->whereKey($again->id)->update(['provider_id' => null]);
        }
    }

    public function test_after_a_cancelled_note_a_new_number_is_used_and_the_old_one_is_kept(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-novo', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();
        $cancelled = $this->makeFiscalDocumentFor($sale, FiscalDocumentStatus::Cancelled, 'nfe-velho');

        $new = $this->issue($sale);

        $this->assertNotSame($cancelled->id, $new->id);
        $this->assertSame($cancelled->number + 1, $new->number);
        $this->assertSame(FiscalDocumentStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(2, FiscalDocument::query()->where('sale_id', $sale->id)->count());
    }

    public function test_the_screen_offers_resend_for_failures_and_issue_again_after_a_cancellation_but_nothing_while_live(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('Caixa');
        $this->actingAs($user);

        $failed = $this->sale();
        $this->makeFiscalDocumentFor($failed, FiscalDocumentStatus::FailedToSend, 'x');
        Livewire::test(ViewSale::class, ['record' => $failed->id])
            ->assertActionExists('issueFiscalDocument', fn ($action): bool => $action->getLabel() === 'Reenviar NFC-e');

        $cancelled = $this->sale();
        $this->makeFiscalDocumentFor($cancelled, FiscalDocumentStatus::Cancelled, 'y');
        Livewire::test(ViewSale::class, ['record' => $cancelled->id])
            ->assertActionVisible('issueFiscalDocument')
            ->assertActionExists('issueFiscalDocument', fn ($action): bool => $action->getLabel() === 'Emitir NFC-e');

        foreach ([FiscalDocumentStatus::Issued, FiscalDocumentStatus::Processing, FiscalDocumentStatus::CancelRequested, FiscalDocumentStatus::CancelFailed] as $status) {
            $live = $this->sale();
            $this->makeFiscalDocumentFor($live, $status, 'z-'.$status->value);

            Livewire::test(ViewSale::class, ['record' => $live->id])->assertActionHidden('issueFiscalDocument');
        }
    }

    public function test_a_sale_with_a_cancelled_note_can_be_cancelled_directly(): void
    {
        $sale = $this->sale();
        $this->makeFiscalDocumentFor($sale, FiscalDocumentStatus::Cancelled, 'nfe-velho');

        ['sale' => $cancelled, 'pending' => $pending] = app(CancelSaleWithFiscalDocument::class)($sale, 'Cliente desistiu');

        $this->assertFalse($pending);
        $this->assertSame(SaleStatus::Cancelled, $cancelled->status);
    }
}

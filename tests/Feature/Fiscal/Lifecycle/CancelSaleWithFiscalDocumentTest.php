<?php

namespace Tests\Feature\Fiscal\Lifecycle;

use App\Actions\Fiscal\ApplyFiscalProviderStatus;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\CancelSaleWithFiscalDocument;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Filament\Tenant\Resources\Sales\Pages\ListSales;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Jobs\CompleteSaleCancellationJob;
use App\Models\FiscalDocument;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Cancelamento de venda com NFC-e (ação combinada): pede o cancelamento da nota e a venda é
 * cancelada sozinha quando a NFE.io confirma; qualquer recusa deixa a venda intacta.
 */
class CancelSaleWithFiscalDocumentTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private const TOKEN = 'TTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTT';

    private Tenant $tenant;

    private PaymentOption $pix;

    private Order $order;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        $this->setWebhookToken($this->tenant, self::TOKEN);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->pix = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Pix', 'show_in_menu' => true, 'fiscal_payment_code' => FiscalPaymentMethod::InstantPayment]);
        [$this->order, $this->sale] = $this->newSale();
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    /** @return array{0: Order, 1: Sale} */
    private function newSale(): array
    {
        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), 40)]]);

        return [$order, $this->finalizeNfceSale([$order], $this->pix)];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function cancel(Sale $sale, string $note = 'Cliente desistiu', ?User $user = null): array
    {
        return app(CancelSaleWithFiscalDocument::class)($sale, $note, $user);
    }

    private function saleStatus(Sale $sale): SaleStatus
    {
        return Sale::query()->findOrFail($sale->id)->status;
    }

    public function test_without_a_note_the_sale_is_cancelled_right_away(): void
    {
        Http::fake();

        ['sale' => $sale, 'pending' => $pending] = $this->cancel($this->sale, user: $this->user('Caixa'));

        $this->assertFalse($pending);
        $this->assertSame(SaleStatus::Cancelled, $sale->status);
        Http::assertNothingSent();
    }

    public function test_with_an_issued_note_it_requests_the_note_cancellation_and_waits(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $user = $this->user('Gerente');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);

        ['sale' => $sale, 'pending' => $pending] = $this->cancel($this->sale, 'Cliente desistiu', $user);

        $this->assertTrue($pending);
        $this->assertSame(SaleStatus::Finalized, $sale->status);
        $this->assertTrue($sale->hasPendingCancellation());
        $this->assertSame('Cliente desistiu', $sale->pending_cancellation_note);
        $this->assertSame($user->id, $sale->pending_cancellation_by_user_id);
        $this->assertSame(FiscalDocumentStatus::CancelRequested, $document->fresh()->status);
        Http::assertSentCount(1);
        $this->assertNotNull($this->order->fresh()->sale_id);
    }

    public function test_the_webhook_confirmation_completes_the_sale_cancellation_end_to_end(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $user = $this->user('Gerente');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);
        $this->cancel($this->sale, 'Cliente desistiu', $user);
        $this->forgetTenantContext();

        $this->postNfeIoWebhook(self::TOKEN, ['id' => 'nfe-1', 'status' => 'Cancelled'])->assertOk();

        $sale = Sale::query()->withoutGlobalScopes()->findOrFail($this->sale->id);
        $this->assertSame(SaleStatus::Cancelled, $sale->status);
        $this->assertSame('Cliente desistiu', $sale->cancellation_note);
        $this->assertSame($user->id, $sale->cancelled_by_user_id);
        $this->assertFalse($sale->hasPendingCancellation());
        $this->assertNull(Order::query()->withoutGlobalScopes()->findOrFail($this->order->id)->sale_id);
        $this->assertSame(FiscalDocumentStatus::Cancelled, FiscalDocument::query()->withoutGlobalScopes()->sole()->status);
    }

    public function test_the_reconciliation_also_completes_the_sale_cancellation(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::sequence()->push(null, 204)->push(['status' => 'Cancelled'])]);
        $this->cancel($this->sale, 'Cliente desistiu', $this->user('Admin'));
        $this->forgetTenantContext();
        FiscalDocument::query()->withoutGlobalScopes()->update(['cancel_requested_at' => now()->subMinutes(30)]);

        $this->artisan('fiscal:sync-status', ['--pausa' => 0])->assertSuccessful();

        $this->assertSame(SaleStatus::Cancelled, Sale::query()->withoutGlobalScopes()->findOrFail($this->sale->id)->status);
    }

    public function test_a_failed_note_cancellation_releases_the_pending_state_and_keeps_the_sale(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);
        $this->cancel($this->sale, 'Cliente desistiu', $this->user('Gerente'));

        app(ApplyFiscalProviderStatus::class)($document->fresh(), 'CancelFailed', ['statusReason' => 'Fora do prazo']);

        $sale = $this->sale->fresh();
        $this->assertSame(SaleStatus::Finalized, $sale->status);
        $this->assertFalse($sale->hasPendingCancellation());
        $this->assertNotNull($this->order->fresh()->sale_id);
        $this->assertSame(FiscalDocumentStatus::CancelFailed, $document->fresh()->status);
    }

    public function test_an_immediate_refusal_undoes_the_pending_state(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(['message' => 'Fora do prazo'], 400)]);

        try {
            $this->cancel($this->sale, 'Cliente desistiu', $this->user('Gerente'));
            $this->fail('Deveria recusar.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('Fora do prazo', $exception->getMessage());
        }

        $this->assertFalse($this->sale->fresh()->hasPendingCancellation());
        $this->assertSame(SaleStatus::Finalized, $this->saleStatus($this->sale));
        $this->assertSame(FiscalDocumentStatus::Issued, $document->fresh()->status);
    }

    public function test_an_ambiguous_failure_keeps_the_pending_state_so_a_late_confirmation_still_cancels_the_sale(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(['message' => 'erro'], 503)]);

        try {
            $this->cancel($this->sale, 'Cliente desistiu', $this->user('Gerente'));
            $this->fail('Deveria sinalizar a falha.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('Desfazer cancelamento pendente', $exception->getMessage());
        }

        $this->assertTrue($this->sale->fresh()->hasPendingCancellation());

        app(ApplyFiscalProviderStatus::class)($document->fresh(), 'Cancelled');

        $this->assertSame(SaleStatus::Cancelled, $this->saleStatus($this->sale));
    }

    public function test_a_second_request_never_overwrites_or_undoes_the_first_pending_state(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);
        $first = $this->user('Gerente');
        $this->cancel($this->sale, 'Motivo do primeiro', $first);

        $second = $this->cancel($this->sale, 'Motivo do segundo', $this->user('Admin'));

        $this->assertTrue($second['pending']);
        $this->assertSame('Motivo do primeiro', $this->sale->fresh()->pending_cancellation_note);
        $this->assertSame($first->id, $this->sale->fresh()->pending_cancellation_by_user_id);
        Http::assertSentCount(1);
    }

    public function test_the_operator_can_discard_a_pending_cancellation(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);
        $this->user('Gerente');
        Livewire::test(ViewSale::class, ['record' => $this->sale->id])->callAction('cancelSale', ['note' => 'Cliente desistiu']);

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->assertActionVisible('discardPendingCancellation')
            ->callAction('discardPendingCancellation')
            ->assertNotified('Cancelamento pendente desfeito');

        $this->assertFalse($this->sale->fresh()->hasPendingCancellation());
        Livewire::test(ViewSale::class, ['record' => $this->sale->id])->assertActionHidden('discardPendingCancellation');
    }

    public function test_the_job_releases_the_pending_state_when_it_exhausts_its_retries(): void
    {
        $this->sale->forceFill(['pending_cancellation_note' => 'Motivo', 'pending_cancellation_at' => now()])->save();
        $this->forgetTenantContext();

        (new CompleteSaleCancellationJob($this->tenant->id, $this->sale->id))->failed(new \RuntimeException('deadlock'));

        $this->assertFalse(Sale::query()->withoutGlobalScopes()->findOrFail($this->sale->id)->hasPendingCancellation());
    }

    public function test_a_processing_or_failed_cancellation_note_blocks_with_guidance(): void
    {
        Http::fake();

        foreach ([[FiscalDocumentStatus::Processing, 'processamento'], [FiscalDocumentStatus::CancelFailed, 'falhou']] as [$status, $hint]) {
            [, $sale] = $this->newSale();
            $this->makeFiscalDocumentFor($sale, $status, 'nfe-'.$status->value);

            try {
                $this->cancel($sale, 'Cliente desistiu', $this->user('Gerente'));
                $this->fail("Deveria recusar com a nota {$status->value}.");
            } catch (SaleException $exception) {
                $this->assertStringContainsString($hint, $exception->getMessage());
            }

            $this->assertFalse($sale->fresh()->hasPendingCancellation());
        }

        Http::assertNothingSent();
    }

    public function test_without_the_note_cancel_permission_it_refuses_and_sends_nothing(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake();

        try {
            $this->cancel($this->sale, 'Cliente desistiu', $this->user('Caixa'));
            $this->fail('O Caixa não cancela nota fiscal.');
        } catch (SaleException $exception) {
            $this->assertStringContainsString('cancelar notas fiscais', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertFalse($this->sale->fresh()->hasPendingCancellation());
    }

    public function test_a_note_awaiting_cancellation_only_records_the_pending_state_and_a_double_click_is_harmless(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::CancelRequested, 'nfe-1');
        Http::fake();
        $user = $this->user('Gerente');

        $first = $this->cancel($this->sale, 'Motivo A', $user);
        $at = $first['sale']->pending_cancellation_at;
        $this->travel(2)->minutes();
        $second = $this->cancel($this->sale, 'Motivo B', $user);

        $this->assertTrue($second['pending']);
        $this->assertEquals($at, $second['sale']->pending_cancellation_at);
        Http::assertNothingSent();
    }

    public function test_a_note_is_required_when_the_sale_has_a_live_note(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake();

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('motivo');
        $this->cancel($this->sale, '   ', $this->user('Gerente'));
    }

    public function test_the_plain_cancel_sale_is_still_blocked_by_a_live_note(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');

        $this->expectException(SaleException::class);
        app(CancelSale::class)($this->sale, 'Cliente desistiu');
    }

    public function test_the_job_is_a_noop_without_a_pending_cancellation_or_for_a_cancelled_sale(): void
    {
        Http::fake();
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Cancelled, 'nfe-1');

        CompleteSaleCancellationJob::dispatchSync($this->tenant->id, $this->sale->id);

        $this->assertSame(SaleStatus::Finalized, $this->saleStatus($this->sale));
    }

    public function test_the_job_releases_the_pending_state_when_the_note_is_not_actually_cancelled(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->level.'|'.$event->message;
        });
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $this->sale->forceFill(['pending_cancellation_note' => 'Motivo', 'pending_cancellation_at' => now()])->save();

        CompleteSaleCancellationJob::dispatchSync($this->tenant->id, $this->sale->id);

        $this->assertSame(SaleStatus::Finalized, $this->saleStatus($this->sale));
        $this->assertFalse($this->sale->fresh()->hasPendingCancellation());
        $this->assertNotEmpty(array_filter($logged, fn (string $line): bool => str_starts_with($line, 'warning|Venda')));
    }

    public function test_the_job_is_unique_per_sale_and_runs_in_the_tenant_context(): void
    {
        $job = new CompleteSaleCancellationJob($this->tenant->id, $this->sale->id);

        $this->assertSame("{$this->tenant->id}:{$this->sale->id}", $job->uniqueId());
        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->middleware());
    }

    public function test_the_job_is_dispatched_only_when_the_note_is_cancelled_and_a_pending_state_exists(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Queue::fake();

        app(ApplyFiscalProviderStatus::class)($document, 'Cancelled');
        Queue::assertNothingPushed();

        [, $other] = $this->newSale();
        $otherDocument = $this->makeFiscalDocumentFor($other, FiscalDocumentStatus::CancelRequested, 'nfe-2');
        $other->forceFill(['pending_cancellation_note' => 'Motivo', 'pending_cancellation_at' => now()])->save();

        app(ApplyFiscalProviderStatus::class)($otherDocument, 'Cancelled');

        Queue::assertPushed(CompleteSaleCancellationJob::class, fn (CompleteSaleCancellationJob $job): bool => $job->saleId === $other->id && $job->tenantId === $this->tenant->id);
        Queue::assertPushedTimes(CompleteSaleCancellationJob::class, 1);
    }

    public function test_another_tenants_pending_sale_is_never_touched(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);
        $this->cancel($this->sale, 'Cliente desistiu', $this->user('Gerente'));

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B'], cnpj: '45723174000110');
        $pix = PaymentOption::create(['tenant_id' => $other->id, 'name' => 'Pix', 'show_in_menu' => true, 'fiscal_payment_code' => FiscalPaymentMethod::InstantPayment]);
        $otherSale = $this->finalizeNfceSale([$this->makeNfceOrder($other, [['product' => $this->makeFiscalProduct($other, 'Pizza', 40)]])], $pix);
        $otherDocument = $this->makeFiscalDocumentFor($otherSale, FiscalDocumentStatus::Issued, 'nfe-b');
        $otherSale->forceFill(['pending_cancellation_note' => 'Motivo B', 'pending_cancellation_at' => now()])->save();

        app(ApplyFiscalProviderStatus::class)($document->fresh(), 'Cancelled');

        $this->assertSame(SaleStatus::Cancelled, Sale::query()->withoutGlobalScopes()->findOrFail($this->sale->id)->status);
        $this->assertSame(SaleStatus::Finalized, Sale::query()->withoutGlobalScopes()->findOrFail($otherSale->id)->status);
        $this->assertTrue(Sale::query()->withoutGlobalScopes()->findOrFail($otherSale->id)->hasPendingCancellation());
        $this->assertSame(FiscalDocumentStatus::Issued, FiscalDocument::query()->withoutGlobalScopes()->findOrFail($otherDocument->id)->status);
    }

    public function test_the_sale_screen_shows_the_pending_banner_and_hides_the_cancel_action(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $this->user('Gerente');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->assertActionVisible('cancelSale')
            ->callAction('cancelSale', ['note' => 'Cliente desistiu'])
            ->assertNotified('Cancelamento solicitado: a venda será cancelada quando a NFE.io confirmar o cancelamento da NFC-e');

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->assertSee('Cancelamento aguardando a NFC-e')
            ->assertSee('Cliente desistiu')
            ->assertActionHidden('cancelSale')
            ->assertActionHidden('cancelFiscalDocument');

        Livewire::test(ListSales::class)->assertTableActionHidden('cancelSale', $this->sale);
    }

    public function test_the_cancel_sale_action_still_cancels_directly_when_there_is_no_note(): void
    {
        $this->user('Caixa');

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->callAction('cancelSale', ['note' => 'Cliente desistiu'])
            ->assertNotified('Venda cancelada');

        $this->assertSame(SaleStatus::Cancelled, $this->saleStatus($this->sale));
    }

    public function test_a_cashier_gets_a_clear_message_when_the_sale_has_an_issued_note(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $this->user('Caixa');
        Http::fake();

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->callAction('cancelSale', ['note' => 'Cliente desistiu'])
            ->assertNotified('Esta venda tem uma NFC-e autorizada. Só quem pode cancelar notas fiscais consegue cancelá-la (e, com ela, a venda).');

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Fiscal\Lifecycle;

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Models\FiscalDocument;
use App\Models\FiscalWebhook;
use App\Models\PaymentOption;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Reconciliação `fiscal:sync-status` (RN-FIS-15): notas paradas são consultadas tenant a tenant,
 * com falha isolada, e o log só ganha linha quando algo mudou.
 */
class SyncFiscalDocumentsCommandTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();

        $this->a = $this->makeNfceTenant('lazzopizza', ['nfeio_company_id' => 'empresa-A', 'nfeio_api_key' => 'chave-A']);
        $this->b = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B', 'nfeio_api_key' => 'chave-B'], cnpj: '45723174000110');
        $this->forgetTenantContext();

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event->level.'|'.$event->message;
        });
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function document(Tenant $tenant, FiscalDocumentStatus $status, string $providerId, ?\Closure $adjust = null): FiscalDocument
    {
        $this->actAsTenant($tenant);
        $pix = PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Pix '.uniqid(), 'show_in_menu' => true, 'fiscal_payment_code' => FiscalPaymentMethod::InstantPayment]);
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($tenant, [['product' => $this->makeFiscalProduct($tenant, 'Pizza '.uniqid(), 40)]])], $pix);
        $document = $this->makeFiscalDocumentFor($sale, $status, $providerId);
        $this->forgetTenantContext();

        if ($adjust !== null) {
            $adjust($document);
            $document->refresh();
        }

        return $document;
    }

    private function fresh(FiscalDocument $document): FiscalDocument
    {
        return FiscalDocument::query()->withoutGlobalScopes()->findOrFail($document->id);
    }

    private function sync(array $options = []): PendingCommand
    {
        return $this->artisan('fiscal:sync-status', ['--pausa' => 0] + $options);
    }

    private function infoLogs(): array
    {
        return array_values(array_filter($this->logged, fn (string $line): bool => str_starts_with($line, 'info|fiscal:sync-status')));
    }

    public function test_a_stuck_processing_note_is_reconciled_and_logged(): void
    {
        $doc = $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-a', 'status' => 'Issued'])]);

        $this->sync()->assertSuccessful();

        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($doc)->status);
        $this->assertNotNull($this->fresh($doc)->authorized_at);
        $this->assertCount(1, $this->infoLogs());
    }

    public function test_nothing_is_logged_when_nothing_changed(): void
    {
        $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-a', 'status' => 'Processing'])]);

        $this->sync()->assertSuccessful();

        $this->assertSame([], $this->infoLogs());
    }

    public function test_simulation_lists_the_change_but_writes_nothing(): void
    {
        $doc = $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-a', 'status' => 'Issued'])]);

        $this->sync(['--simular' => true])->assertSuccessful()->expectsOutputToContain('lazzopizza');

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($doc)->status);
        $this->assertSame([], $this->infoLogs());
    }

    public function test_recent_notes_and_old_notes_are_skipped(): void
    {
        $recent = $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-recent', fn (FiscalDocument $d) => $d->update(['sent_at' => now()->subMinutes(3)]));
        $old = $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-old', fn (FiscalDocument $d) => FiscalDocument::query()->withoutGlobalScopes()->whereKey($d->id)->update(['sent_at' => now()->subDays(8)]));
        Http::fake();

        $this->sync()->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($recent)->status);
        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($old)->status);
    }

    public function test_the_window_options_are_respected(): void
    {
        $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a', fn (FiscalDocument $d) => $d->update(['sent_at' => now()->subMinutes(3)]));
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Issued'])]);

        $this->sync(['--minutos' => 1])->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_issued_cancelled_and_idless_notes_are_not_queried(): void
    {
        $this->document($this->a, FiscalDocumentStatus::Issued, 'nfe-issued');
        $this->document($this->a, FiscalDocumentStatus::Cancelled, 'nfe-cancelled');
        $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-x', fn (FiscalDocument $d) => $d->update(['provider_id' => null]));
        Http::fake();

        $this->sync()->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_limit_is_per_tenant_and_the_oldest_pending_note_goes_first(): void
    {
        foreach (['n1' => 40, 'n2' => 90, 'n3' => 60] as $id => $minutesAgo) {
            $this->document($this->a, FiscalDocumentStatus::Processing, $id, fn (FiscalDocument $d) => $d->update(['sent_at' => now()->subMinutes($minutesAgo)]));
        }
        $this->document($this->b, FiscalDocumentStatus::Processing, 'nb');
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Processing'])]);

        $this->sync(['--limite' => 2])->assertSuccessful();

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/n2'));
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/n3'));
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/n1'));
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/nb'));
    }

    public function test_the_simulation_follows_the_same_rules_as_the_real_run(): void
    {
        $doc = $this->document($this->a, FiscalDocumentStatus::CancelRequested, 'nfe-a');
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Issued'])]);

        // Cancelamento pedido há 12 min e a NFE.io ainda diz "Issued": a execução real ignora, a simulação também.
        $this->sync(['--simular' => true])->assertSuccessful()->doesntExpectOutputToContain('issued');
        $this->sync()->assertSuccessful();

        $this->assertSame(FiscalDocumentStatus::CancelRequested, $this->fresh($doc)->status);
    }

    public function test_the_removal_of_old_webhook_records_is_scheduled_and_works(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'model:prune'));
        $this->assertNotNull($event);

        FiscalWebhook::query()->withoutGlobalScopes()->insert([
            ['tenant_id' => $this->a->id, 'received_at' => now()->subDays(91), 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->a->id, 'received_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('model:prune', ['--model' => [FiscalWebhook::class]])->assertSuccessful();

        $this->assertSame(1, FiscalWebhook::query()->withoutGlobalScopes()->count());
    }

    public function test_each_tenant_is_queried_with_its_own_credentials_and_only_its_own_notes(): void
    {
        $a = $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        $b = $this->document($this->b, FiscalDocumentStatus::Processing, 'nfe-b');
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Issued'])]);

        $this->sync()->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/companies/empresa-A/consumerinvoices/nfe-a') && $r->header('Authorization') === ['chave-A']);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/companies/empresa-B/consumerinvoices/nfe-b') && $r->header('Authorization') === ['chave-B']);
        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($a)->status);
        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($b)->status);
    }

    public function test_a_tenant_without_the_feature_is_ignored(): void
    {
        $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        $b = $this->document($this->b, FiscalDocumentStatus::Processing, 'nfe-b');
        Tenant::query()->whereKey($this->a->id)->update(['plan_id' => $this->planWithAllFeaturesAndPdv()->id]);
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Issued'])]);

        $this->sync()->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'empresa-B'));
        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($b)->status);
    }

    public function test_a_failing_tenant_does_not_stop_the_others_and_the_run_reports_failure(): void
    {
        $a = $this->document($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        $b = $this->document($this->b, FiscalDocumentStatus::Processing, 'nfe-b');
        Http::fake([
            'api.nfse.io/v2/companies/empresa-A/*' => Http::response(['message' => 'erro'], 500),
            'api.nfse.io/v2/companies/empresa-B/*' => Http::response(['status' => 'Issued']),
        ]);

        $this->sync()->assertFailed();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($a)->status);
        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($b)->status);
        $this->assertContains('warning|fiscal:sync-status teve falhas ao consultar a NFE.io', $this->logged);
        $this->assertStringNotContainsString('chave-A', implode("\n", $this->logged));
    }

    public function test_a_cancellation_requested_note_is_reconciled_to_cancelled(): void
    {
        $doc = $this->document($this->a, FiscalDocumentStatus::CancelRequested, 'nfe-a');
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Cancelled'])]);

        $this->sync()->assertSuccessful();

        $this->assertSame(FiscalDocumentStatus::Cancelled, $this->fresh($doc)->status);
        $this->assertNotNull($this->fresh($doc)->cancelled_at);
    }

    public function test_the_command_is_scheduled_every_ten_minutes_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'fiscal:sync-status'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}

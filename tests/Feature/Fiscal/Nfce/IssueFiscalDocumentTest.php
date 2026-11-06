<?php

namespace Tests\Feature\Fiscal\Nfce;

use App\Actions\Fiscal\BuildNfcePayload;
use App\Actions\Fiscal\IssueFiscalDocument;
use App\Actions\Fiscal\ReserveFiscalNumber;
use App\Actions\Fiscal\SyncFiscalDocumentStatus;
use App\Actions\Sales\CancelSale;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\SaleException;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Services\Fiscal\NfeIoClient;
use App\Support\Fiscal\BuyerResolver;
use App\Support\Fiscal\NfcePayload;
use App\Support\Fiscal\ResolveProductFiscalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Emissão da NFC-e (Fase 1.2): sucesso, falha de envio, revalidação no momento de emitir,
 * HTTP fora de transação, lock anti-duplicidade e sincronização de status.
 */
class IssueFiscalDocumentTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private const API_KEY = 'chave-secreta-da-api';

    private Tenant $tenant;

    private PaymentOption $pix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function sale(float $price = 40): Sale
    {
        return $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), $price)]])], $this->pix);
    }

    private function issue(Sale $sale): FiscalDocument
    {
        return app(IssueFiscalDocument::class)($sale, FiscalDocumentDecision::Manual);
    }

    public function test_issuing_posts_the_payload_and_stores_the_provider_id(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();

        $document = $this->issue($sale);

        $this->assertSame(FiscalDocumentStatus::Processing, $document->status);
        $this->assertSame('nfe-1', $document->provider_id);
        $this->assertSame('Processing', $document->provider_status);
        $this->assertSame(1, $document->number);
        $this->assertNotNull($document->sent_at);
        $this->assertNull($document->error_message);
        $this->assertSame(FiscalDocumentDecision::Manual, $document->decision);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.nfse.io/v2/companies/company-123/consumerinvoices'
                && $request->header('Authorization') === [self::API_KEY]
                && $request['id'] === (string) $document->id
                && $request['number'] === 1
                && count($request['items']) === 1;
        });
    }

    public function test_an_immediately_issued_note_gets_authorized_at(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Issued'], 200)]);

        $document = $this->issue($this->sale());

        $this->assertSame(FiscalDocumentStatus::Issued, $document->status);
        $this->assertNotNull($document->authorized_at);
    }

    public function test_the_http_call_runs_outside_any_database_transaction(): void
    {
        $before = DB::transactionLevel();
        $during = null;
        Http::fake(function () use (&$during) {
            $during = DB::transactionLevel();

            return Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202);
        });

        $this->issue($this->sale());

        $this->assertSame($before, $during);
    }

    public function test_an_http_failure_keeps_the_number_and_a_resend_reuses_it(): void
    {
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['message' => 'Erro interno'], 500)
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();

        $failed = $this->issue($sale);

        $this->assertSame(FiscalDocumentStatus::FailedToSend, $failed->status);
        $this->assertSame(1, $failed->number);
        $this->assertStringContainsString('HTTP 500', $failed->error_message);
        $this->assertStringNotContainsString(self::API_KEY, $failed->error_message);

        $resent = $this->issue($sale);

        $this->assertSame($failed->id, $resent->id);
        $this->assertSame(1, $resent->number);
        $this->assertSame(FiscalDocumentStatus::Processing, $resent->status);
        $this->assertNull($resent->error_message);
        $this->assertSame(1, FiscalDocument::query()->count());
    }

    public function test_a_connection_failure_is_recorded_without_losing_the_number(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        $document = $this->issue($this->sale());

        $this->assertSame(FiscalDocumentStatus::FailedToSend, $document->status);
        $this->assertStringContainsString('Não foi possível conectar', $document->error_message);
        $this->assertStringNotContainsString(self::API_KEY, $document->error_message);
    }

    public function test_an_accepted_response_without_id_is_not_treated_as_sent(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['status' => 'Processing'], 202)]);

        $document = $this->issue($this->sale());

        $this->assertSame(FiscalDocumentStatus::FailedToSend, $document->status);
        $this->assertNull($document->provider_id);
        $this->assertStringContainsString('identificador', $document->error_message);
    }

    public function test_a_payload_problem_keeps_the_reservation_and_sends_nothing(): void
    {
        Http::fake();
        $sale = $this->sale();
        Sale::query()->whereKey($sale->id)->update(['total' => 99]);

        try {
            $this->issue($sale->refresh());
            $this->fail('Deveria recusar totais que não fecham.');
        } catch (FiscalDocumentException $exception) {
            $this->assertStringContainsString('não fecham', $exception->getMessage());
        }

        Http::assertNothingSent();
        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocumentStatus::Reserved, $document->status);
        $this->assertNotNull($document->error_message);
    }

    public function test_the_lock_blocks_a_concurrent_issue_for_the_same_sale(): void
    {
        Http::fake();
        $sale = $this->sale();
        $lock = Cache::lock("tenant:{$this->tenant->id}:fiscal:sale:{$sale->id}", 60);
        $this->assertTrue($lock->get());

        try {
            $this->issue($sale);
            $this->fail('Deveria recusar enquanto outra emissão está em andamento.');
        } catch (FiscalDocumentException $exception) {
            $this->assertStringContainsString('em andamento', $exception->getMessage());
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->count());
    }

    public function test_the_lock_is_released_after_issuing(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();
        $this->issue($sale);

        $lock = Cache::lock("tenant:{$this->tenant->id}:fiscal:sale:{$sale->id}", 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_a_sale_with_a_live_note_cannot_be_issued_again(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();
        $this->issue($sale);

        $this->expectException(FiscalDocumentException::class);
        $this->expectExceptionMessage('já tem uma NFC-e');
        $this->issue($sale);
    }

    public function test_it_is_blocked_without_the_feature(): void
    {
        Http::fake();
        $sale = $this->sale();
        $plan = $this->planWithAllFeaturesAndPdv();
        Tenant::query()->whereKey($this->tenant->id)->update(['plan_id' => $plan->id]);

        $this->assertBlocked($sale, 'não está disponível no plano');
    }

    public function test_it_is_blocked_when_emission_is_not_enabled(): void
    {
        Http::fake();
        $sale = $this->sale();
        $this->nfceSettings($this->tenant)->update(['enabled_at' => null]);

        $this->assertBlocked($sale, 'não está habilitada');
    }

    public function test_it_is_blocked_for_an_unsupported_tax_regime(): void
    {
        Http::fake();
        $sale = $this->sale();
        $this->nfceSettings($this->tenant)->update(['tax_regime' => 'lucro_presumido']);

        $this->assertBlocked($sale, 'Simples Nacional');
    }

    public function test_it_is_blocked_when_the_tenant_is_not_ready(): void
    {
        Http::fake();
        $sale = $this->sale();
        $this->nfceSettings($this->tenant)->update(['legal_name' => null]);

        $this->assertBlocked($sale, 'razão social');
    }

    public function test_it_is_blocked_when_a_product_of_the_sale_lost_its_fiscal_data(): void
    {
        Http::fake();
        $sale = $this->sale();
        DB::table('products')->update(['ncm' => null]);

        $this->assertBlocked($sale, 'sem dados fiscais');
    }

    public function test_only_the_products_of_the_sale_matter_not_the_whole_catalog(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();
        $this->makeProduct($this->tenant, 'Produto sem fiscal', 10);

        $this->assertSame(FiscalDocumentStatus::Processing, $this->issue($sale)->status);
    }

    public function test_it_is_blocked_when_a_payment_option_of_the_sale_has_no_fiscal_code(): void
    {
        Http::fake();
        $sale = $this->sale();
        $this->pix->update(['fiscal_payment_code' => null]);

        $this->assertBlocked($sale, 'sem código fiscal');
    }

    public function test_it_is_blocked_for_a_cancelled_sale(): void
    {
        Http::fake();
        $sale = $this->sale();
        $sale->update(['status' => 'cancelled']);

        $this->assertBlocked($sale->refresh(), 'finalizada');
    }

    public function test_production_requires_an_issued_test_note_first(): void
    {
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-teste', 'status' => 'Issued'])
            ->push(['id' => 'nfe-producao', 'status' => 'Issued'])]);
        $this->nfceSettings($this->tenant)->update(['environment' => 'production']);
        $sale = $this->sale();

        $this->assertBlocked($sale, 'homologação');

        $this->nfceSettings($this->tenant)->update(['environment' => 'test']);
        $this->assertSame(FiscalDocumentStatus::Issued, $this->issue($this->sale())->status);

        $this->nfceSettings($this->tenant)->update(['environment' => 'production']);
        $production = $this->issue($sale);

        $this->assertSame('production', $production->environment->value);
        $this->assertSame(1, $production->number);
        $this->assertSame(FiscalDocumentStatus::Issued, $production->status);
    }

    public function test_sync_moves_a_processing_note_to_issued(): void
    {
        Http::fake(['api.nfse.io/v2/companies/company-123/consumerinvoices' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $document = $this->issue($this->sale());

        Http::fake(['api.nfse.io/v2/companies/company-123/consumerinvoices/nfe-1' => Http::response(['id' => 'nfe-1', 'status' => 'Issued'])]);
        $synced = app(SyncFiscalDocumentStatus::class)($document);

        $this->assertSame(FiscalDocumentStatus::Issued, $synced->status);
        $this->assertSame('Issued', $synced->provider_status);
        $this->assertNotNull($synced->authorized_at);
    }

    public function test_sync_records_a_rejected_note_and_unknown_statuses_stay_processing(): void
    {
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)
            ->push(['status' => 'AlgumaCoisaNova'])
            ->push(['status' => 'IssueFailed', 'statusReason' => 'Rejeição 539'])
            ->push(['status' => 'Processing'])
            ->push(['status' => 'Cancelled'])]);
        $document = $this->issue($this->sale());
        $sync = app(SyncFiscalDocumentStatus::class);

        $unknown = $sync($document);
        $this->assertSame(FiscalDocumentStatus::Processing, $unknown->status);
        $this->assertSame('AlgumaCoisaNova', $unknown->provider_status);

        $failed = $sync($document);
        $this->assertSame(FiscalDocumentStatus::Failed, $failed->status);
        $this->assertSame('Rejeição 539', $failed->error_message);

        // Um "Processing" atrasado nunca revive uma nota já rejeitada.
        $this->assertSame(FiscalDocumentStatus::Failed, $sync($document)->status);

        $cancelled = $sync($document);
        $this->assertSame(FiscalDocumentStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_sync_without_a_provider_id_is_refused(): void
    {
        Http::fake();
        $document = $this->issue($this->sale());
        $document->update(['provider_id' => null]);

        $this->expectException(FiscalDocumentException::class);
        app(SyncFiscalDocumentStatus::class)($document);
    }

    public function test_the_pdf_url_is_fetched_on_demand(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['uri' => 'https://files.example/danfe.pdf'])]);

        $url = app(NfeIoClient::class)->pdfUrl($this->nfceSettings($this->tenant), 'nfe-1');

        $this->assertSame('https://files.example/danfe.pdf', $url);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.nfse.io/v2/companies/company-123/consumerinvoices/nfe-1/pdf');
    }

    public function test_credentials_never_leak_into_errors_or_logs(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $document = $this->issue($this->sale());

        $this->assertStringNotContainsString(self::API_KEY, (string) $document->error_message);
        $this->assertStringNotContainsString(self::API_KEY, json_encode($document->toArray()));
        $this->assertStringContainsString('credenciais', $document->error_message);
    }

    private function assertBlocked(Sale $sale, string $messagePart): void
    {
        try {
            $this->issue($sale);
            $this->fail('A emissão deveria ter sido bloqueada.');
        } catch (FiscalDocumentException $exception) {
            $this->assertStringContainsString($messagePart, $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->where('sale_id', $sale->id)->count());
    }

    public function test_the_document_is_live_during_the_http_call_so_the_sale_cannot_be_cancelled(): void
    {
        $sale = $this->sale();
        $reason = null;
        Http::fake(function () use ($sale, &$reason) {
            try {
                app(CancelSale::class)($sale, 'Cliente desistiu');
            } catch (SaleException $exception) {
                $reason = $exception->getMessage();
            }

            return Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202);
        });

        $this->issue($sale);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('NFC-e', $reason);
        $this->assertSame(SaleStatus::Finalized, $sale->refresh()->status);
    }

    public function test_nothing_is_sent_if_the_sale_is_cancelled_after_the_number_was_reserved(): void
    {
        Http::fake();
        $sale = $this->sale();
        $this->app->bind(BuildNfcePayload::class, fn () => new class(app(ResolveProductFiscalData::class), app(BuyerResolver::class)) extends BuildNfcePayload
        {
            public function __invoke(FiscalDocument $document, Sale $sale, TenantFiscalSettings $settings): NfcePayload
            {
                $payload = parent::__invoke($document, $sale, $settings);
                Sale::query()->whereKey($sale->id)->update(['status' => 'cancelled']);

                return $payload;
            }
        });

        try {
            $this->issue($sale);
            $this->fail('Deveria recusar enviar a nota de uma venda cancelada.');
        } catch (FiscalDocumentException $exception) {
            $this->assertStringContainsString('cancelada', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(FiscalDocumentStatus::Reserved, FiscalDocument::query()->sole()->status);
    }

    public function test_a_definitive_4xx_failure_is_safe_to_resend_but_5xx_and_timeouts_are_ambiguous(): void
    {
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['message' => 'CFOP inválido'], 400)
            ->push(['message' => 'Erro'], 500)
            ->push(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();

        $first = $this->issue($sale);
        $this->assertFalse($first->isAmbiguousSend());
        $this->assertNull($first->sent_at);

        $second = $this->issue($sale);
        $this->assertTrue($second->isAmbiguousSend());
        $this->assertNotNull($second->sent_at);

        $this->assertSame(FiscalDocumentStatus::Processing, $this->issue($sale)->status);
        $this->assertFalse(FiscalDocument::query()->sole()->isAmbiguousSend());
    }

    public function test_a_send_that_never_recorded_the_provider_id_stops_blocking_after_a_while(): void
    {
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-2', 'status' => 'Processing'], 202)]);
        $sale = $this->sale();
        $stuck = app(ReserveFiscalNumber::class)($sale, $this->tenant, $this->nfceSettings($this->tenant), FiscalDocumentDecision::Manual);
        $stuck->update(['status' => FiscalDocumentStatus::Processing, 'sent_at' => now()]);

        $this->assertTrue($sale->hasLiveFiscalDocument());

        $this->travel(11)->minutes();
        $this->assertFalse($sale->hasLiveFiscalDocument());

        $resent = $this->issue($sale);
        $this->assertSame($stuck->id, $resent->id);
        $this->assertSame(1, $resent->number);
        $this->assertSame('nfe-2', $resent->provider_id);
    }

    public function test_a_late_processing_answer_never_downgrades_an_issued_note(): void
    {
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-1', 'status' => 'Issued'])
            ->push(['status' => 'Processing'])]);
        $document = $this->issue($this->sale());

        $synced = app(SyncFiscalDocumentStatus::class)($document);

        $this->assertSame(FiscalDocumentStatus::Issued, $synced->status);
    }

    public function test_an_undecryptable_api_key_becomes_a_safe_error_not_an_exception(): void
    {
        Http::fake();
        $sale = $this->sale();
        DB::table('tenant_fiscal_settings')->update(['nfeio_api_key' => 'lixo-que-nao-decifra']);

        $document = $this->issue($sale);

        $this->assertSame(FiscalDocumentStatus::FailedToSend, $document->status);
        $this->assertStringContainsString('Informe a chave novamente', $document->error_message);
        Http::assertNothingSent();
    }
}

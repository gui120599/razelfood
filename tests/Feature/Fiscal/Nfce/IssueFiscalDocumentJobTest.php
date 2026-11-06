<?php

namespace Tests\Feature\Fiscal\Nfce;

use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Jobs\IssueFiscalDocumentJob;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Emissão automática (RN-FIS-12): o fechamento da venda despacha o job só quando deve,
 * depois da resposta, e o job roda no contexto do tenant certo, sem tentar de novo.
 */
class IssueFiscalDocumentJobTest extends TestCase
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
        $this->auto = $this->makeFiscalPaymentOption($this->tenant, 'Pix automático', FiscalPaymentMethod::InstantPayment, auto: true);
        $this->manual = $this->makeFiscalPaymentOption($this->tenant, 'Pix manual', FiscalPaymentMethod::InstantPayment);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function closeSale(PaymentOption $option): Sale
    {
        return $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza '.uniqid(), 40)]])], $option);
    }

    public function test_closing_a_sale_with_an_automatic_payment_dispatches_the_job_after_the_response(): void
    {
        Bus::fake();

        $sale = $this->closeSale($this->auto);

        Bus::assertDispatchedAfterResponse(IssueFiscalDocumentJob::class, fn (IssueFiscalDocumentJob $job): bool => $job->tenantId === $this->tenant->id
            && $job->saleId === $sale->id);
        Bus::assertDispatchedAfterResponseTimes(IssueFiscalDocumentJob::class, 1);
    }

    public function test_after_the_response_the_automatic_note_is_issued_without_any_queue_worker(): void
    {
        $this->fakeNfeIo('Processing');

        $sale = $this->closeSale($this->auto);
        $this->assertSame(0, FiscalDocument::query()->withoutGlobalScopes()->count(), 'Nada é emitido antes da resposta.');

        $this->forgetTenantContext();
        $this->runAfterResponseJobs();

        $document = FiscalDocument::query()->withoutGlobalScopes()->sole();
        $this->assertSame($sale->id, $document->sale_id);
        $this->assertSame($this->tenant->id, $document->tenant_id);
        $this->assertSame(FiscalDocumentDecision::Automatic, $document->decision);
        $this->assertNull(CurrentTenant::get(), 'O contexto do tenant é liberado ao fim.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Nenhum registro ficou parado na tabela de fila.');
    }

    public function test_no_job_when_no_payment_is_automatic(): void
    {
        Bus::fake();

        $this->closeSale($this->manual);

        Bus::assertNotDispatchedAfterResponse(IssueFiscalDocumentJob::class);
    }

    public function test_no_job_when_emission_is_not_enabled(): void
    {
        Bus::fake();
        $this->nfceSettings($this->tenant)->update(['enabled_at' => null]);

        $this->closeSale($this->auto);

        Bus::assertNotDispatchedAfterResponse(IssueFiscalDocumentJob::class);
    }

    public function test_no_job_without_the_feature(): void
    {
        Bus::fake();
        Tenant::query()->whereKey($this->tenant->id)->update(['plan_id' => $this->planWithAllFeaturesAndPdv()->id]);
        $this->actAsTenant(Tenant::query()->find($this->tenant->id));

        $this->closeSale($this->auto);

        Bus::assertNotDispatchedAfterResponse(IssueFiscalDocumentJob::class);
    }

    public function test_the_job_has_a_single_attempt(): void
    {
        $this->assertSame(1, (new IssueFiscalDocumentJob(1, 1))->tries);
    }

    public function test_the_job_issues_inside_the_tenants_context_and_releases_it(): void
    {
        $sale = $this->closeSale($this->manual);
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'nfe-1', 'status' => 'Processing'], 202)]);
        $this->forgetTenantContext();

        IssueFiscalDocumentJob::dispatchSync($this->tenant->id, $sale->id);

        $this->assertNull(CurrentTenant::get());
        $document = FiscalDocument::query()->withoutGlobalScopes()->sole();
        $this->assertSame($this->tenant->id, $document->tenant_id);
        $this->assertSame(FiscalDocumentStatus::Processing, $document->status);
        $this->assertSame(FiscalDocumentDecision::Automatic, $document->decision);
        Http::assertSentCount(1);
    }

    public function test_the_job_does_not_fail_when_a_business_rule_blocks_the_issue(): void
    {
        $sale = $this->closeSale($this->manual);
        Http::fake();
        $this->nfceSettings($this->tenant)->update(['legal_name' => null]);
        $this->forgetTenantContext();
        Log::partialMock()->shouldReceive('warning')->once();

        IssueFiscalDocumentJob::dispatchSync($this->tenant->id, $sale->id);

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->withoutGlobalScopes()->count());
    }

    public function test_an_unexpected_error_is_reported_and_never_escapes_the_job(): void
    {
        $sale = $this->closeSale($this->manual);
        Http::fake(['api.nfse.io/*' => Http::response(['message' => 'erro'], 500)]);
        $this->forgetTenantContext();

        // Dentro do `terminate()` uma exceção relançada abortaria os demais callbacks de terminação.
        IssueFiscalDocumentJob::dispatchSync($this->tenant->id, $sale->id);

        $this->assertNull(CurrentTenant::get());
        Http::assertSentCount(1);
    }

    public function test_a_job_never_touches_a_sale_of_another_tenant(): void
    {
        $sale = $this->closeSale($this->manual);
        Http::fake();

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'company-outra']);
        $this->forgetTenantContext();

        IssueFiscalDocumentJob::dispatchSync($other->id, $sale->id);

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->withoutGlobalScopes()->count());
    }
}

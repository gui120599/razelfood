<?php

namespace Tests\Feature\Fiscal\Lifecycle;

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Enums\TenantStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalWebhook;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Webhook da NFE.io por tenant (Fase 1.3): token opaco na URL, HMAC-SHA1 com o segredo do tenant,
 * payload sempre gravado antes de decidir, documento de outro tenant nunca tocado.
 */
class NfeIoWebhookTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private const TOKEN_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const TOKEN_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    private Tenant $a;

    private Tenant $b;

    private FiscalDocument $docA;

    private FiscalDocument $docB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();

        $this->a = $this->makeNfceTenant('lazzopizza', ['nfeio_company_id' => 'empresa-A', 'nfeio_webhook_secret' => 'segredo-A']);
        $this->docA = $this->documentOf($this->a, FiscalDocumentStatus::Processing, 'nfe-a');
        $this->setWebhookToken($this->a, self::TOKEN_A);

        $this->b = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B', 'nfeio_webhook_secret' => 'segredo-B'], cnpj: '45723174000110');
        $this->docB = $this->documentOf($this->b, FiscalDocumentStatus::Processing, 'nfe-b');
        $this->setWebhookToken($this->b, self::TOKEN_B);

        $this->forgetTenantContext();
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function documentOf(Tenant $tenant, FiscalDocumentStatus $status, string $providerId): FiscalDocument
    {
        $this->actAsTenant($tenant);
        $pix = PaymentOption::create(['tenant_id' => $tenant->id, 'name' => 'Pix', 'show_in_menu' => true, 'fiscal_payment_code' => FiscalPaymentMethod::InstantPayment]);
        $sale = $this->finalizeNfceSale([$this->makeNfceOrder($tenant, [['product' => $this->makeFiscalProduct($tenant, 'Pizza', 40)]])], $pix);

        return $this->makeFiscalDocumentFor($sale, $status, $providerId);
    }

    private function fresh(FiscalDocument $document): FiscalDocument
    {
        return FiscalDocument::query()->withoutGlobalScopes()->findOrFail($document->id);
    }

    /** @return Collection<int, FiscalWebhook> */
    private function webhooks()
    {
        return FiscalWebhook::query()->withoutGlobalScopes()->orderBy('id')->get();
    }

    public function test_a_valid_webhook_updates_the_document_and_is_recorded(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued', 'event' => 'status'], 'segredo-A')->assertOk();

        $document = $this->fresh($this->docA);
        $this->assertSame(FiscalDocumentStatus::Issued, $document->status);
        $this->assertNotNull($document->authorized_at);

        $record = $this->webhooks()->sole();
        $this->assertSame($this->a->id, $record->tenant_id);
        $this->assertSame('nfe-a', $record->provider_id);
        $this->assertSame('status', $record->event);
        $this->assertTrue($record->signature_valid);
        $this->assertSame('applied', $record->outcome);
        $this->assertSame($document->id, $record->fiscal_document_id);
        $this->assertNotNull($record->processed_at);
        $this->assertSame('Issued', $record->payload['status']);
    }

    public function test_the_signature_tolerates_a_sha1_prefix_and_uppercase_hex(): void
    {
        $payload = ['id' => 'nfe-a', 'status' => 'Issued'];
        $hex = hash_hmac('sha1', json_encode($payload), 'segredo-A');

        $this->postNfeIoWebhook(self::TOKEN_A, $payload, signature: 'sha1='.strtoupper($hex))->assertOk();
        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($this->docA)->status);
    }

    public function test_an_oversized_body_is_recorded_truncated_and_invalid_utf8_does_not_break_recording(): void
    {
        $big = ['id' => 'nfe-a', 'status' => 'Processing', 'blob' => str_repeat('x', 70000)];
        $this->postNfeIoWebhook(self::TOKEN_A, $big, 'segredo-A')->assertOk();

        $record = $this->webhooks()->sole();
        $this->assertTrue($record->payload['_truncated']);
        $this->assertArrayNotHasKey('blob', $record->payload);

        $raw = '{"id":"nfe-a","status":"Processing","nome":"Jos'."\xE9".'"}';
        $this->call('POST', route('webhooks.nfeio', ['token' => self::TOKEN_A]), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE' => hash_hmac('sha1', $raw, 'segredo-A'),
        ], $raw)->assertOk();

        $this->assertCount(2, $this->webhooks());
    }

    public function test_an_invalid_signature_is_403_but_the_payload_is_still_recorded_and_nothing_changes(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], signature: 'assinatura-errada')->assertForbidden();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
        $record = $this->webhooks()->sole();
        $this->assertFalse($record->signature_valid);
        $this->assertSame('rejected', $record->outcome);
        $this->assertSame('nfe-a', $record->provider_id);
    }

    public function test_a_signature_made_with_another_tenants_secret_is_rejected(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-B')->assertForbidden();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
    }

    public function test_a_missing_signature_is_403_and_recorded(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], secret: null)->assertForbidden();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
        $this->assertCount(1, $this->webhooks());
        $this->assertFalse($this->webhooks()->sole()->signature_valid);
    }

    public function test_a_tenant_without_a_configured_secret_never_accepts_unverified_calls(): void
    {
        $this->nfceSettings($this->a)->forceFill(['nfeio_webhook_secret' => null])->save();

        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], secret: null)->assertForbidden();
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'qualquer-coisa')->assertForbidden();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
        $this->assertNull($this->webhooks()->first()->signature_valid);
    }

    public function test_an_unknown_or_malformed_token_is_404_and_records_nothing(): void
    {
        $this->postNfeIoWebhook(str_repeat('Z', 48), ['id' => 'nfe-a', 'status' => 'Issued'])->assertNotFound();
        $this->postNfeIoWebhook('curto', ['id' => 'nfe-a', 'status' => 'Issued'])->assertNotFound();
        $this->postNfeIoWebhook(strtolower(self::TOKEN_A), ['id' => 'nfe-a', 'status' => 'Issued'])->assertNotFound();

        $this->assertCount(0, $this->webhooks());
        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
    }

    public function test_a_document_of_another_tenant_is_never_touched(): void
    {
        // Chamada válida na URL do tenant A, mas com o id da nota do tenant B.
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-b', 'status' => 'Issued'], 'segredo-A')->assertOk();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docB)->status);
        $record = $this->webhooks()->sole();
        $this->assertSame($this->a->id, $record->tenant_id);
        $this->assertNull($record->fiscal_document_id);
        $this->assertSame('unknown_document', $record->outcome);
    }

    public function test_each_tenant_url_updates_only_its_own_document(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_B, ['id' => 'nfe-b', 'status' => 'Issued'], 'segredo-B')->assertOk();

        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($this->docB)->status);
        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
    }

    public function test_replaying_the_same_webhook_is_idempotent(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A')->assertOk();
        $authorizedAt = $this->fresh($this->docA)->authorized_at;

        $this->travel(5)->minutes();
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A')->assertOk();

        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($this->docA)->status);
        $this->assertEquals($authorizedAt, $this->fresh($this->docA)->authorized_at);
        $this->assertSame(['applied', 'unchanged'], $this->webhooks()->pluck('outcome')->all());
    }

    public function test_a_late_processing_notice_never_downgrades_an_issued_note(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A')->assertOk();
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Processing'], 'segredo-A')->assertOk();

        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($this->docA)->status);
    }

    public function test_a_cancellation_notice_marks_the_note_cancelled(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A');
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Cancelled'], 'segredo-A')->assertOk();

        $document = $this->fresh($this->docA);
        $this->assertSame(FiscalDocumentStatus::Cancelled, $document->status);
        $this->assertNotNull($document->cancelled_at);
    }

    public function test_a_rejection_notice_stores_the_reason(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'IssueFailed', 'statusReason' => 'Rejeição 539'], 'segredo-A')->assertOk();

        $document = $this->fresh($this->docA);
        $this->assertSame(FiscalDocumentStatus::Failed, $document->status);
        $this->assertSame('Rejeição 539', $document->error_message);
    }

    public function test_a_tenant_without_the_feature_records_the_call_but_changes_nothing(): void
    {
        Tenant::query()->whereKey($this->a->id)->update(['plan_id' => $this->planWithAllFeaturesAndPdv()->id]);

        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A')->assertOk();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
        $this->assertSame('feature_off', $this->webhooks()->sole()->outcome);
    }

    public function test_a_suspended_tenant_still_resolves_and_a_deleted_one_does_not(): void
    {
        Tenant::query()->whereKey($this->a->id)->update(['status' => TenantStatus::Suspended]);
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A')->assertOk();
        $this->assertSame(FiscalDocumentStatus::Issued, $this->fresh($this->docA)->status);

        Tenant::query()->whereKey($this->b->id)->update(['deleted_at' => now()]);
        $this->postNfeIoWebhook(self::TOKEN_B, ['id' => 'nfe-b', 'status' => 'Issued'], 'segredo-B')->assertNotFound();
        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docB)->status);
    }

    public function test_a_payload_without_id_or_status_is_recorded_and_ignored(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['event' => 'ping'], 'segredo-A')->assertOk();
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a'], 'segredo-A')->assertOk();

        $this->assertSame(FiscalDocumentStatus::Processing, $this->fresh($this->docA)->status);
        $this->assertSame(['unknown_document', 'unknown_document'], $this->webhooks()->pluck('outcome')->all());
    }

    public function test_the_tenant_context_is_released_after_the_request(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A')->assertOk();

        $this->assertNull(CurrentTenant::get());
    }

    public function test_secrets_and_signatures_never_reach_the_logs(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], signature: 'assinatura-secreta-errada')->assertForbidden();
        $this->postNfeIoWebhook(str_repeat('Z', 48), ['id' => 'nfe-a'])->assertNotFound();

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('assinatura-secreta-errada', $all);
        $this->assertStringNotContainsString('segredo-A', $all);
        $this->assertStringNotContainsString(self::TOKEN_A, $all);
    }

    public function test_the_webhook_is_reachable_without_session_or_csrf(): void
    {
        $response = $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A');

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_the_sale_is_untouched_by_a_note_notice(): void
    {
        $this->postNfeIoWebhook(self::TOKEN_A, ['id' => 'nfe-a', 'status' => 'Issued'], 'segredo-A');

        $this->assertSame(1, Sale::query()->withoutGlobalScopes()->where('tenant_id', $this->a->id)->count());
    }
}

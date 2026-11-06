<?php

namespace Tests\Feature\Fiscal\Lifecycle;

use App\Actions\Fiscal\ApplyFiscalProviderStatus;
use App\Actions\Fiscal\CancelFiscalDocument;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Exceptions\FiscalDocumentException;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Models\FiscalDocument;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Cancelamento da NFC-e (RN-FIS-17): só nota autorizada, pedido à NFE.io, confirmação depois,
 * recusa imediata não muda o estado, permissão própria.
 */
class CancelFiscalDocumentTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private const API_KEY = 'chave-secreta-da-api';

    private Tenant $tenant;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();
        $this->tenant = $this->makeNfceTenant('lazzopizza');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $pix = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Pix', 'show_in_menu' => true, 'fiscal_payment_code' => FiscalPaymentMethod::InstantPayment]);
        $this->sale = $this->finalizeNfceSale([$this->makeNfceOrder($this->tenant, [['product' => $this->makeFiscalProduct($this->tenant, 'Pizza', 40)]])], $pix);
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

    private function cancel(FiscalDocument $document, ?User $user = null): FiscalDocument
    {
        return app(CancelFiscalDocument::class)($document, $user);
    }

    public function test_cancelling_an_issued_note_sends_a_delete_and_marks_cancel_requested(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $user = $this->actingAsRole('Gerente');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);

        $result = $this->cancel($document, $user);

        $this->assertSame(FiscalDocumentStatus::CancelRequested, $result->status);
        $this->assertNotNull($result->cancel_requested_at);
        $this->assertSame($user->id, $result->cancel_requested_by_user_id);
        $this->assertNull($result->cancelled_at);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.nfse.io/v2/companies/company-123/consumerinvoices/nfe-1'
            && $request->header('Authorization') === [self::API_KEY]);
    }

    public function test_only_an_issued_note_can_be_cancelled(): void
    {
        Http::fake();

        foreach ([FiscalDocumentStatus::Processing, FiscalDocumentStatus::Cancelled, FiscalDocumentStatus::CancelRequested, FiscalDocumentStatus::CancelFailed, FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Failed] as $status) {
            $document = $this->makeFiscalDocumentFor($this->sale, $status, 'nfe-'.$status->value);

            try {
                $this->cancel($document);
                $this->fail("Não deveria cancelar uma nota {$status->value}.");
            } catch (FiscalDocumentException $exception) {
                $this->assertStringContainsString('autorizada', $exception->getMessage());
            }

            FiscalDocument::query()->whereKey($document->id)->delete();
        }

        Http::assertNothingSent();
    }

    public function test_an_immediate_refusal_keeps_the_state_and_returns_the_message(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(['message' => 'Prazo de cancelamento expirado'], 400)]);

        try {
            $this->cancel($document);
            $this->fail('Deveria recusar.');
        } catch (FiscalDocumentException $exception) {
            $this->assertStringContainsString('Prazo de cancelamento expirado', $exception->getMessage());
            $this->assertStringNotContainsString('Atualizar status', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $fresh = $document->fresh();
        $this->assertSame(FiscalDocumentStatus::Issued, $fresh->status);
        $this->assertNull($fresh->cancel_requested_at);
    }

    public function test_a_server_error_or_timeout_asks_the_operator_to_check_the_status_first(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');

        foreach ([Http::response(['message' => 'erro'], 503), fn () => throw new ConnectionException('timeout')] as $answer) {
            Http::fake(['api.nfse.io/*' => $answer]);

            try {
                $this->cancel($document);
                $this->fail('Deveria falhar.');
            } catch (FiscalDocumentException $exception) {
                $this->assertStringContainsString('Atualizar status', $exception->getMessage());
            }

            $this->assertSame(FiscalDocumentStatus::Issued, $document->fresh()->status);
        }
    }

    public function test_it_is_blocked_without_the_feature(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Tenant::query()->whereKey($this->tenant->id)->update(['plan_id' => $this->planWithAllFeaturesAndPdv()->id]);
        Http::fake();

        $this->expectException(FiscalDocumentException::class);

        try {
            $this->cancel($document);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_per_sale_lock_blocks_a_concurrent_operation(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake();
        $lock = Cache::lock("tenant:{$this->tenant->id}:fiscal:sale:{$this->sale->id}", 60);
        $this->assertTrue($lock->get());

        try {
            $this->cancel($document);
            $this->fail('Deveria recusar enquanto há outra operação.');
        } catch (FiscalDocumentException $exception) {
            $this->assertStringContainsString('em andamento', $exception->getMessage());
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_a_stale_document_is_reread_under_the_lock(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $stale = $document->replicate();
        $stale->id = $document->id;
        $stale->exists = true;
        FiscalDocument::query()->whereKey($document->id)->update(['status' => FiscalDocumentStatus::Cancelled->value]);
        Http::fake();

        $this->expectException(FiscalDocumentException::class);
        $this->cancel($stale);
    }

    public function test_the_confirmation_and_the_failure_come_from_the_provider_status(): void
    {
        $apply = app(ApplyFiscalProviderStatus::class);
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::CancelRequested, 'nfe-1');

        // "Issued" enquanto o cancelamento está em andamento não é um resultado: ignora.
        $this->assertFalse($apply($document, 'Issued'));
        $this->assertSame(FiscalDocumentStatus::CancelRequested, $document->fresh()->status);

        $this->assertTrue($apply($document, 'CancelFailed', ['statusReason' => 'Fora do prazo']));
        $failed = $document->fresh();
        $this->assertSame(FiscalDocumentStatus::CancelFailed, $failed->status);
        $this->assertSame('Fora do prazo', $failed->error_message);

        // O provedor pode voltar a informar "Issued" depois de um cancelamento que falhou.
        $this->assertTrue($apply($failed, 'Issued'));
        $this->assertSame(FiscalDocumentStatus::Issued, $document->fresh()->status);

        $this->assertTrue($apply($document->fresh(), 'Cancelled'));
        $this->assertNotNull($document->fresh()->cancelled_at);
        $this->assertFalse($apply($document->fresh(), 'Issued'));
        $this->assertSame(FiscalDocumentStatus::Cancelled, $document->fresh()->status);
    }

    public function test_a_cancellation_the_provider_never_confirms_is_released_after_a_while(): void
    {
        $apply = app(ApplyFiscalProviderStatus::class);
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::CancelRequested, 'nfe-1');
        $this->sale->forceFill(['pending_cancellation_note' => 'Motivo', 'pending_cancellation_at' => now()])->save();

        $this->travel(ApplyFiscalProviderStatus::CANCEL_UNCONFIRMED_MINUTES + 1)->minutes();
        $this->assertTrue($apply($document->fresh(), 'Issued'));

        $released = $document->fresh();
        $this->assertSame(FiscalDocumentStatus::Issued, $released->status);
        $this->assertStringContainsString('não foi confirmado', $released->error_message);
        $this->assertFalse($this->sale->fresh()->hasPendingCancellation());
    }

    public function test_a_stale_model_never_overwrites_what_a_webhook_wrote_meanwhile(): void
    {
        $apply = app(ApplyFiscalProviderStatus::class);
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Processing, 'nfe-1');
        $stale = FiscalDocument::query()->findOrFail($document->id);

        // O webhook grava "Issued" enquanto a reconciliação (com o model velho) ainda consulta.
        $apply($document, 'Issued');
        $this->assertSame(FiscalDocumentStatus::Processing, $stale->status);

        $this->assertFalse($apply($stale, 'Processing'));
        $this->assertSame(FiscalDocumentStatus::Issued, $document->fresh()->status);
    }

    public function test_the_cancel_request_never_overwrites_a_cancelled_status_written_during_the_call(): void
    {
        $document = $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $user = $this->actingAsRole('Gerente');
        Http::fake(function () use ($document) {
            // O webhook "Cancelled" chega antes de o DELETE responder.
            app(ApplyFiscalProviderStatus::class)($document->fresh(), 'Cancelled');

            return Http::response(null, 204);
        });

        $result = $this->cancel($document, $user);

        $this->assertSame(FiscalDocumentStatus::Cancelled, $result->status);
        $this->assertNull($result->fresh()->cancel_requested_at);
    }

    public function test_the_cancel_action_exists_only_for_issued_notes_and_for_users_with_the_permission(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        Http::fake(['api.nfse.io/*' => Http::response(null, 204)]);

        $this->actingAsRole('Caixa');
        Livewire::test(ViewSale::class, ['record' => $this->sale->id])->assertActionHidden('cancelFiscalDocument');

        $this->actingAsRole('Gerente');
        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->assertActionVisible('cancelFiscalDocument')
            ->callAction('cancelFiscalDocument')
            ->assertNotified('Cancelamento da NFC-e solicitado: aguardando a confirmação da NFE.io');

        $this->assertSame(FiscalDocumentStatus::CancelRequested, FiscalDocument::query()->sole()->status);

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])->assertActionHidden('cancelFiscalDocument');
    }

    public function test_a_refused_cancellation_from_the_screen_shows_the_reason(): void
    {
        $this->makeFiscalDocumentFor($this->sale, FiscalDocumentStatus::Issued, 'nfe-1');
        $this->actingAsRole('Admin');
        Http::fake(['api.nfse.io/*' => Http::response(['message' => 'Fora do prazo'], 422)]);

        Livewire::test(ViewSale::class, ['record' => $this->sale->id])
            ->callAction('cancelFiscalDocument')
            ->assertNotified('Não foi possível cancelar a NFC-e');

        $this->assertSame(FiscalDocumentStatus::Issued, FiscalDocument::query()->sole()->status);
    }

    public function test_only_admin_and_manager_have_the_cancel_permission(): void
    {
        foreach (['Admin' => true, 'Gerente' => true, 'Caixa' => false, 'Atendente' => false, 'Entregador' => false] as $role => $allowed) {
            $user = $this->actingAsRole($role);
            $this->assertSame($allowed, $user->can('cancel_fiscal_document'), $role);
        }

        $this->assertTrue($this->actingAsRole('Caixa')->can('emit_fiscal_document'));
    }
}

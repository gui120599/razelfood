<?php

namespace Tests\Feature\Fiscal\Nfce;

use App\Actions\Fiscal\IssueFiscalDocument;
use App\Actions\Fiscal\ReserveFiscalNumber;
use App\Actions\Sales\CancelSale;
use App\Actions\Sales\FinalizeSale;
use App\Actions\Tenants\SeedDefaultTenantOptions;
use App\Enums\CardBrand as CardBrandEnum;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\CardBrand;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Support\Sales\SaleCancellationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Critério global §16.2 para a Fase 1.2: dois tenants nunca enxergam documentos, bandeiras
 * ou credenciais um do outro; venda com NFC-e viva não é cancelada; bandeiras padrão semeadas.
 */
class NfceTenantIsolationTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        Http::preventStrayRequests();
        $this->a = $this->makeNfceTenant('lazzopizza', ['nfeio_company_id' => 'empresa-A', 'nfeio_api_key' => 'chave-A']);
        $this->b = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'empresa-B', 'nfeio_api_key' => 'chave-B'], cnpj: '45723174000110');
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function saleOf(Tenant $tenant): Sale
    {
        $this->actAsTenant($tenant);
        $pix = $this->makeFiscalPaymentOption($tenant, 'Pix', FiscalPaymentMethod::InstantPayment);

        return $this->finalizeNfceSale([$this->makeNfceOrder($tenant, [['product' => $this->makeFiscalProduct($tenant, 'Pizza', 40)]])], $pix);
    }

    public function test_each_tenant_issues_with_its_own_credentials_and_sees_only_its_documents(): void
    {
        Http::fake(['api.nfse.io/*' => Http::sequence()
            ->push(['id' => 'nfe-a', 'status' => 'Processing'], 202)
            ->push(['id' => 'nfe-b', 'status' => 'Processing'], 202)]);
        $saleA = $this->saleOf($this->a);
        $saleB = $this->saleOf($this->b);

        $this->actAsTenant($this->a);
        app(IssueFiscalDocument::class)($saleA, FiscalDocumentDecision::Manual);
        $this->actAsTenant($this->b);
        app(IssueFiscalDocument::class)($saleB, FiscalDocumentDecision::Manual);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/companies/empresa-A/') && $request->header('Authorization') === ['chave-A']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/companies/empresa-B/') && $request->header('Authorization') === ['chave-B']);

        $this->actAsTenant($this->a);
        $this->assertSame(['nfe-a'], FiscalDocument::query()->pluck('provider_id')->all());
        $this->actAsTenant($this->b);
        $this->assertSame(['nfe-b'], FiscalDocument::query()->pluck('provider_id')->all());
        $this->assertNull(FiscalDocument::query()->where('sale_id', $saleA->id)->first());
    }

    public function test_a_tenant_cannot_issue_the_sale_of_another_tenant(): void
    {
        Http::fake();
        $saleA = $this->saleOf($this->a);
        $this->actAsTenant($this->b);

        try {
            app(IssueFiscalDocument::class)($saleA, FiscalDocumentDecision::Manual);
        } catch (\Throwable) {
        }

        $this->assertSame(0, FiscalDocument::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->count());
    }

    public function test_card_brands_are_isolated_and_a_foreign_brand_is_refused_at_checkout(): void
    {
        $brandB = $this->makeCardBrand($this->b);
        $this->actAsTenant($this->a);
        $credit = $this->makeFiscalPaymentOption($this->a, 'Crédito', FiscalPaymentMethod::CreditCard);
        $order = $this->makeNfceOrder($this->a, [['product' => $this->makeFiscalProduct($this->a, 'Pizza', 40)]]);

        $this->assertNull(CardBrand::query()->find($brandB->id));

        $this->expectException(SaleException::class);
        $this->expectExceptionMessage('Bandeira de cartão inválida');
        $this->finalizeNfceSale([$order], $credit, [['payment_option_id' => $credit->id, 'amount' => 40, 'card_brand_id' => $brandB->id]]);
    }

    public function test_the_payment_keeps_the_brand_id_and_a_name_snapshot(): void
    {
        $this->actAsTenant($this->a);
        $credit = $this->makeFiscalPaymentOption($this->a, 'Crédito', FiscalPaymentMethod::CreditCard);
        $brand = $this->makeCardBrand($this->a, CardBrandEnum::Mastercard);
        $order = $this->makeNfceOrder($this->a, [['product' => $this->makeFiscalProduct($this->a, 'Pizza', 40)]]);

        $sale = $this->finalizeNfceSale([$order], $credit, [['payment_option_id' => $credit->id, 'amount' => 40, 'card_brand_id' => $brand->id]]);

        $payment = SalePayment::query()->where('sale_id', $sale->id)->sole();
        $this->assertSame($brand->id, $payment->card_brand_id);
        $this->assertSame('Mastercard', $payment->card_brand);
        $this->assertSame($brand->id, $payment->cardBrand->id);
    }

    public function test_default_card_brands_are_seeded_for_a_new_tenant_and_the_seeder_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'Nova', 'slug' => 'nova', 'status' => 'active', 'whatsapp_number' => '5511999999999']);

        app(SeedDefaultTenantOptions::class)($tenant);
        app(SeedDefaultTenantOptions::class)($tenant);

        $brands = CardBrand::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('name')->all();
        $this->assertSame(['Visa', 'Mastercard', 'American Express', 'Sorocred', 'Elo', 'Alelo', 'Hipercard', 'Outra'], $brands);
    }

    public function test_a_sale_with_a_live_note_cannot_be_cancelled(): void
    {
        $sale = $this->saleOf($this->a);
        $document = $this->reserveFor($sale);

        foreach ([FiscalDocumentStatus::Issued, FiscalDocumentStatus::Processing, FiscalDocumentStatus::CancelRequested, FiscalDocumentStatus::CancelFailed] as $status) {
            $document->update(['status' => $status]);

            try {
                app(CancelSale::class)($sale, 'Cliente desistiu');
                $this->fail("A venda não deveria ser cancelada com a nota {$status->value}.");
            } catch (SaleException $exception) {
                $this->assertStringContainsString('NFC-e', $exception->getMessage());
            }

            $this->assertSame(SaleStatus::Finalized, $sale->refresh()->status);
            $this->assertNotNull(app(SaleCancellationGuard::class)->blockedReason($sale));
        }
    }

    public function test_a_sale_without_a_live_note_can_still_be_cancelled(): void
    {
        $sale = $this->saleOf($this->a);
        $this->reserveFor($sale)->update(['status' => FiscalDocumentStatus::FailedToSend]);

        $cancelled = app(CancelSale::class)($sale, 'Cliente desistiu');

        $this->assertSame(SaleStatus::Cancelled, $cancelled->status);
    }

    public function test_a_cancelled_note_does_not_block_cancelling_the_sale(): void
    {
        $sale = $this->saleOf($this->a);
        $this->reserveFor($sale)->update(['status' => FiscalDocumentStatus::Cancelled]);

        $this->assertNull(app(SaleCancellationGuard::class)->blockedReason($sale));
    }

    public function test_a_tenant_without_the_feature_stays_inert(): void
    {
        $tenant = $this->makeSaleTenant('semnfe');
        $this->actAsTenant($tenant);
        $pix = $this->makePaymentOption($tenant, 'Pix');
        $order = $this->makeSaleOrder($tenant);

        Http::fake();
        $sale = app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $pix->id, 'amount' => 40]]);

        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertFalse($sale->hasLiveFiscalDocument());
    }

    private function reserveFor(Sale $sale): FiscalDocument
    {
        $tenant = Tenant::query()->find($sale->tenant_id);

        return app(ReserveFiscalNumber::class)($sale, $tenant, $this->nfceSettings($tenant), FiscalDocumentDecision::Manual);
    }
}

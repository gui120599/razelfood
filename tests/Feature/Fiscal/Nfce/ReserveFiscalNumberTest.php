<?php

namespace Tests\Feature\Fiscal\Nfce;

use App\Actions\Fiscal\ReserveFiscalNumber;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalPaymentMethod;
use App\Exceptions\FiscalDocumentException;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberSequence;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * RN-FIS-01..03 e MT-05: numeração fiscal por CNPJ + ambiente + modelo + série,
 * reservada antes do envio e reaproveitada enquanto não consumida.
 */
class ReserveFiscalNumberTest extends TestCase
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
        $this->pix = $this->makeFiscalPaymentOption($this->tenant, 'Pix', FiscalPaymentMethod::InstantPayment);
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

    private function reserve(Sale $sale, ?Tenant $tenant = null): FiscalDocument
    {
        $tenant ??= $this->tenant;

        return app(ReserveFiscalNumber::class)($sale, $tenant, $this->nfceSettings($tenant), FiscalDocumentDecision::Manual);
    }

    public function test_numbers_are_sequential_per_cnpj_environment_model_and_series(): void
    {
        $first = $this->reserve($this->sale());
        $second = $this->reserve($this->sale());

        $this->assertSame([1, 2], [$first->number, $second->number]);
        $this->assertSame(65, $first->model);
        $this->assertSame(FiscalDocumentStatus::Reserved, $first->status);
        $this->assertSame(2, FiscalNumberSequence::query()->value('last_number'));
        $this->assertSame(1, FiscalNumberSequence::query()->count());
    }

    public function test_a_resend_reuses_the_unconsumed_number(): void
    {
        $sale = $this->sale();
        $first = $this->reserve($sale);
        $first->update(['status' => FiscalDocumentStatus::FailedToSend]);

        $again = $this->reserve($sale);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $again->number);
        $this->assertSame(1, FiscalNumberSequence::query()->value('last_number'));
        $this->assertSame(1, FiscalDocument::query()->count());
    }

    public function test_a_rejected_note_reuses_its_number_too(): void
    {
        $sale = $this->sale();
        $first = $this->reserve($sale);
        $first->update(['status' => FiscalDocumentStatus::Failed, 'provider_id' => 'old']);

        $this->assertSame($first->id, $this->reserve($sale)->id);
    }

    public function test_a_cancelled_note_consumed_its_number_so_a_new_one_is_opened(): void
    {
        $sale = $this->sale();
        $first = $this->reserve($sale);
        $first->update(['status' => FiscalDocumentStatus::Cancelled]);

        $second = $this->reserve($sale);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $second->number);
        $this->assertSame(2, FiscalDocument::query()->where('sale_id', $sale->id)->count());
    }

    public function test_it_refuses_while_the_sale_has_a_live_document(): void
    {
        foreach ([FiscalDocumentStatus::Issued, FiscalDocumentStatus::Processing, FiscalDocumentStatus::CancelRequested, FiscalDocumentStatus::CancelFailed] as $status) {
            $sale = $this->sale();
            $this->reserve($sale)->update(['status' => $status]);

            try {
                $this->reserve($sale);
                $this->fail("Deveria recusar com o documento {$status->value}.");
            } catch (FiscalDocumentException) {
                $this->assertSame(1, FiscalDocument::query()->where('sale_id', $sale->id)->count());
            }
        }
    }

    public function test_a_change_of_series_or_environment_opens_a_new_sequence(): void
    {
        $sale = $this->sale();
        $first = $this->reserve($sale);

        $this->nfceSettings($this->tenant)->update(['nfce_series' => 2]);
        $second = $this->reserve($sale);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame([2, 1], [$second->series, $second->number]);
        $this->assertSame(2, FiscalNumberSequence::query()->count());
    }

    public function test_two_tenants_with_the_same_cnpj_share_one_sequence_without_collision(): void
    {
        $sale = $this->sale();
        $first = $this->reserve($sale);

        $other = $this->makeNfceTenant('emporiodapizza', ['nfeio_company_id' => 'company-outra']);
        $pix = $this->makeFiscalPaymentOption($other, 'Pix', FiscalPaymentMethod::InstantPayment);
        $otherSale = $this->finalizeNfceSale([$this->makeNfceOrder($other, [['product' => $this->makeFiscalProduct($other, 'Pizza', 40)]])], $pix);
        $second = $this->reserve($otherSale, $other);

        $this->assertSame(1, FiscalNumberSequence::query()->count());
        $this->assertSame($first->sequence_id, $second->sequence_id);
        $this->assertSame([1, 2], [$first->number, $second->number]);
        $this->assertSame($other->id, $second->tenant_id);
    }

    public function test_the_database_refuses_a_duplicated_number_in_a_sequence(): void
    {
        $first = $this->reserve($this->sale());
        $sale = $this->sale();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('fiscal_documents')->insert([
            'tenant_id' => $this->tenant->id, 'sale_id' => $sale->id, 'sequence_id' => $first->sequence_id,
            'environment' => 'test', 'model' => 65, 'series' => 1, 'number' => $first->number,
            'status' => 'reserved', 'decision' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_it_requires_a_valid_issuer_cnpj(): void
    {
        $sale = $this->sale();
        Tenant::query()->whereKey($this->tenant->id)->update(['cnpj' => null]);

        $this->expectException(FiscalDocumentException::class);
        $this->reserve($sale, Tenant::query()->find($this->tenant->id));
    }
}

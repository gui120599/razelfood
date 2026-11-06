<?php

namespace Tests\Feature\Fiscal\Panel;

use App\Enums\FiscalDocumentStatus;
use App\Enums\TenantStatus;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberSequence;
use App\Models\Sale;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Contador de NFC-e por tenant na central: só notas AUTORIZADAS EM PRODUÇÃO (homologação não é
 * cobrada), no mês corrente e no total.
 */
class TenantNfceCountersTest extends TestCase
{
    use RefreshDatabase;

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('central'));
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => TenantStatus::Active, 'whatsapp_number' => '5511999999999']);
    }

    private function note(Tenant $tenant, FiscalDocumentStatus $status, string $environment, CarbonInterface $authorizedAt): void
    {
        $sale = Sale::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'number' => ++$this->number, 'status' => 'finalized', 'items_total' => 10, 'delivery_fee' => 0, 'total' => 10, 'finalized_at' => now()]);
        $sequence = FiscalNumberSequence::query()->firstOrCreate(
            ['issuer_cnpj' => str_pad((string) $tenant->id, 14, '0', STR_PAD_LEFT), 'environment' => $environment, 'model' => 65, 'series' => 1],
            ['tenant_id' => $tenant->id, 'last_number' => 0],
        );

        FiscalDocument::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'sequence_id' => $sequence->id,
            'environment' => $environment, 'model' => 65, 'series' => 1, 'number' => ++$this->number,
            'status' => $status, 'decision' => 'manual', 'authorized_at' => $authorizedAt,
        ]);
    }

    public function test_the_counters_only_count_authorized_production_notes(): void
    {
        $a = $this->tenant('lazzopizza');
        $b = $this->tenant('emporiodapizza');
        $none = $this->tenant('semnotas');

        $this->note($a, FiscalDocumentStatus::Issued, 'production', now());
        $this->note($a, FiscalDocumentStatus::Issued, 'production', now()->startOfMonth()->addMinute());
        $this->note($a, FiscalDocumentStatus::Issued, 'production', now()->startOfMonth()->subDay());
        $this->note($a, FiscalDocumentStatus::Issued, 'test', now());
        $this->note($a, FiscalDocumentStatus::Cancelled, 'production', now());
        $this->note($a, FiscalDocumentStatus::Processing, 'production', now());
        $this->note($b, FiscalDocumentStatus::Issued, 'production', now());
        // Válidas na SEFAZ até a confirmação do cancelamento: contam. Apagadas (soft delete): não.
        $this->note($b, FiscalDocumentStatus::CancelRequested, 'production', now());
        $this->note($b, FiscalDocumentStatus::Issued, 'production', now());
        FiscalDocument::withoutGlobalScopes()->latest('id')->first()->delete();

        $this->actingAsPlatformAdmin();

        Livewire::test(ListTenants::class)
            ->assertCanSeeTableRecords([$a, $b, $none])
            ->assertTableColumnStateSet('nfce_month_count', 2, $a)
            ->assertTableColumnStateSet('nfce_total_count', 3, $a)
            ->assertTableColumnStateSet('nfce_month_count', 2, $b)
            ->assertTableColumnStateSet('nfce_total_count', 2, $b)
            ->assertTableColumnStateSet('nfce_month_count', 0, $none)
            ->assertTableColumnStateSet('nfce_total_count', 0, $none);
    }
}

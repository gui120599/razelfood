<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\AddSalePayment;
use App\Actions\Sales\AddStandaloneItem;
use App\Actions\Sales\AttachOrderItems;
use App\Actions\Sales\DiscardEmptySale;
use App\Actions\Sales\FinalizeOpenSale;
use App\Actions\Sales\SetSaleClient;
use App\Actions\Sales\StartSale;
use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Feature;
use App\Models\OrderItem;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\FeatureKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesNfceFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Descarte de rascunhos vazios (ao sair da tela e pelo comando agendado, por tenant) e o que
 * acontece com rascunhos quando o tenant perde o PDV.
 */
class DiscardEmptySalesTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesNfceFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        $this->cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function age(Sale $sale, int $hours = 5): void
    {
        DB::table('sales')->where('id', $sale->id)->update(['updated_at' => now()->subHours($hours)]);
    }

    public function test_an_empty_draft_is_discarded_and_deleted(): void
    {
        $sale = app(StartSale::class)();

        $this->assertTrue(app(DiscardEmptySale::class)($sale));
        $this->assertNull(Sale::find($sale->id));
    }

    public function test_a_draft_with_any_content_is_kept(): void
    {
        $withItem = app(StartSale::class)();
        app(AddStandaloneItem::class)($withItem, $this->makeProduct($this->tenant, 'Pizza', 40)->id);

        $withClient = app(StartSale::class)();
        app(SetSaleClient::class)($withClient, Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria', 'phone' => '11988887777'])->id);

        $withPayment = app(StartSale::class)();
        app(AddSalePayment::class)($withPayment, ['payment_option_id' => $this->cash->id, 'amount' => 10]);

        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeProduct($this->tenant, 'Pizza 2', 40)]]);
        $withOrder = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);

        foreach ([$withItem, $withClient, $withPayment, $withOrder] as $sale) {
            $this->assertFalse(app(DiscardEmptySale::class)($sale));
            $this->assertNotNull(Sale::find($sale->id));
        }

        $this->assertSame($withOrder->id, OrderItem::where('order_id', $order->id)->value('sale_id'));
    }

    public function test_a_finalized_sale_is_never_discarded(): void
    {
        $sale = app(StartSale::class)();
        app(AddStandaloneItem::class)($sale, $this->makeProduct($this->tenant, 'Pizza', 40)->id);
        app(AddSalePayment::class)($sale, ['payment_option_id' => $this->cash->id, 'amount' => 40]);
        $finalized = app(FinalizeOpenSale::class)($sale);

        $this->assertFalse(app(DiscardEmptySale::class)($finalized));
        $this->assertSame(SaleStatus::Finalized, Sale::find($finalized->id)->status);
    }

    public function test_the_command_discards_only_stale_empty_drafts_of_tenants_with_the_pdv(): void
    {
        $staleEmpty = app(StartSale::class)();
        $freshEmpty = app(StartSale::class)();
        $staleWithItem = app(StartSale::class)();
        app(AddStandaloneItem::class)($staleWithItem, $this->makeProduct($this->tenant, 'Pizza', 40)->id);
        $this->age($staleEmpty);
        $this->age($staleWithItem);

        $other = $this->makeSaleTenant('emporiodapizza');
        $this->actAsTenant($other);
        $otherStale = app(StartSale::class)();
        $this->age($otherStale);

        $noPdv = $this->makeSaleTenant('semfeature', withPdv: false);
        $noPdvSale = Sale::withoutGlobalScopes()->create(['tenant_id' => $noPdv->id, 'status' => SaleStatus::Open, 'items_total' => 0, 'discount_total' => 0, 'delivery_fee' => 0, 'total' => 0]);
        $this->age($noPdvSale);
        $this->forgetTenantContext();

        $this->artisan('sales:discard-empty-drafts')->assertSuccessful();

        $this->assertNull(Sale::withoutGlobalScopes()->find($staleEmpty->id), 'Rascunho vazio e antigo cai.');
        $this->assertNull(Sale::withoutGlobalScopes()->find($otherStale->id), 'Vale para cada tenant com o PDV.');
        $this->assertNotNull(Sale::withoutGlobalScopes()->find($freshEmpty->id), 'Rascunho recente fica.');
        $this->assertNotNull(Sale::withoutGlobalScopes()->find($staleWithItem->id), 'Rascunho com conteúdo nunca é apagado.');
        $this->assertNotNull(Sale::withoutGlobalScopes()->find($noPdvSale->id), 'Tenant sem a feature não é processado.');
    }

    public function test_losing_the_pdv_discards_empty_drafts_and_cancels_the_ones_with_content(): void
    {
        $empty = app(StartSale::class)();

        $order = $this->makeNfceOrder($this->tenant, [['product' => $this->makeProduct($this->tenant, 'Pizza', 40)]]);
        $reserved = app(AttachOrderItems::class)(app(StartSale::class)(), $order->id);

        $this->tenant->featureOverrides()->create([
            'feature_id' => Feature::query()->where('key', FeatureKey::PDV)->value('id'),
            'enabled' => false,
        ]);

        $this->assertNull(Sale::withoutGlobalScopes()->find($empty->id));
        $this->assertSame(SaleStatus::Cancelled, Sale::withoutGlobalScopes()->find($reserved->id)->status);
        $this->assertStringContainsString('PDV foi desativado', Sale::withoutGlobalScopes()->find($reserved->id)->cancellation_note);
        $this->assertNull(OrderItem::where('order_id', $order->id)->value('sale_id'), 'A reserva é liberada.');
    }
}

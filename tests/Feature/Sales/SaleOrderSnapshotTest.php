<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\FinalizeSale;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Models\Client;
use App\Models\SaleOrder;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Snapshot dos pedidos cobrados (`sale_orders`): sobrevive ao cancelamento da
 * venda, quando `orders.sale_id` volta a nulo.
 */
class SaleOrderSnapshotTest extends TestCase
{
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeSaleTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(SeedDefaultTenantRoles::class)($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    public function test_finalizing_records_a_snapshot_of_each_order(): void
    {
        $cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Maria', 'phone' => '11999990000']);
        $a = $this->makeSaleOrder($this->tenant, 40, OrderStatus::Preparing);
        $a->update(['client_id' => $client->id]);
        $b = $this->makeSaleOrder($this->tenant, 25.5);

        $sale = app(FinalizeSale::class)([$a->id, $b->id], [], [['payment_option_id' => $cash->id, 'amount' => 65.5]]);

        $this->assertCount(2, $sale->saleOrders);
        $first = $sale->saleOrders->sortBy('order_id')->first();
        $this->assertSame($this->tenant->id, $first->tenant_id);
        $this->assertSame($a->id, $first->order_id);
        $this->assertSame($a->displayNumber(), $first->order_label);
        $this->assertSame(OrderStatus::Preparing, $first->order_status);
        $this->assertSame('Maria', $first->client_name);
        $this->assertSame('40.00', $first->order_total);
    }

    public function test_the_snapshot_survives_the_cancellation_and_is_shown_on_the_sale_page(): void
    {
        $cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $order = $this->makeSaleOrder($this->tenant, 40);
        $sale = app(FinalizeSale::class)([$order->id], [], [['payment_option_id' => $cash->id, 'amount' => 40]]);

        app(CancelSale::class)($sale, 'Cobrança duplicada');

        $this->assertNull($order->fresh()->sale_id);
        $this->assertSame(0, $sale->orders()->count(), 'O vínculo vivo foi desfeito...');
        $this->assertSame(1, SaleOrder::where('sale_id', $sale->id)->count(), '...mas o snapshot permanece.');

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(ViewSale::class, ['record' => $sale->id])
            ->assertOk()
            ->assertSee($order->displayNumber())
            ->assertSee('Cancelada');
    }

    public function test_standalone_only_sale_has_no_snapshot(): void
    {
        $cash = $this->makePaymentOption($this->tenant, 'Dinheiro', isCash: true);
        $product = $this->makeProduct($this->tenant, 'Água', 5);

        $sale = app(FinalizeSale::class)([], [['product_id' => $product->id, 'quantity' => 2]], [['payment_option_id' => $cash->id, 'amount' => 10]]);

        $this->assertCount(0, $sale->saleOrders);
    }
}

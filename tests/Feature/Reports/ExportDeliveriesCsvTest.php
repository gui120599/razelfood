<?php

namespace Tests\Feature\Reports;

use App\Actions\Reports\ExportDeliveriesCsv;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportDeliveriesCsvTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste', 'slug' => 'tenant-teste',
            'status' => TenantStatus::Active, 'whatsapp_number' => '5511999999999',
        ]);
        CurrentTenant::set($this->tenant);
    }

    private function contents(): string
    {
        return app(ExportDeliveriesCsv::class)->contents(
            CarbonImmutable::today()->subDays(7),
            CarbonImmutable::today(),
        );
    }

    public function test_csv_has_one_row_per_delivered_order_with_courier(): void
    {
        $courier = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ana Entregas']);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Guilherme', 'phone' => '11999990000']);

        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $client->id,
            'order_number' => 42, 'assigned_delivery_user_id' => $courier->id,
            'items_total' => 40, 'delivery_fee' => 7, 'grand_total' => 47,
            'status' => OrderStatus::Delivered, 'delivery_neighborhood' => 'Centro',
            'opened_at' => now()->subDay()->setTime(19, 0),
            'in_transit_at' => now()->subDay()->setTime(19, 30),
            'delivered_at' => now()->subDay()->setTime(20, 0),
        ]);
        OrderPayment::create(['order_id' => $order->id, 'payment_option_name' => 'Pix', 'is_cash' => false, 'amount' => 47]);

        // Fora do período — não deve entrar.
        Order::create([
            'tenant_id' => $this->tenant->id, 'assigned_delivery_user_id' => $courier->id,
            'items_total' => 10, 'grand_total' => 10, 'status' => OrderStatus::Delivered,
            'opened_at' => now()->subDays(90), 'in_transit_at' => now()->subDays(90), 'delivered_at' => now()->subDays(90),
        ]);

        $lines = array_values(array_filter(explode("\n", trim($this->contents()))));

        $this->assertCount(2, $lines); // header + 1
        $this->assertStringContainsString('Entregador,Pedido,Entregue', $lines[0]);
        $this->assertStringContainsString('Ana Entregas', $lines[1]);
        $this->assertStringContainsString('42', $lines[1]);
        $this->assertStringContainsString('Centro', $lines[1]);
        $this->assertStringContainsString('30 min', $lines[1]);
    }
}

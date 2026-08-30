<?php

namespace Tests\Feature\Reports;

use App\Actions\Reports\ExportOrdersCsv;
use App\Enums\CancellationReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportOrdersCsvTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);
        CurrentTenant::set($this->tenant);
    }

    private function contents(): string
    {
        return app(ExportOrdersCsv::class)->contents(
            CarbonImmutable::today()->subDays(7),
            CarbonImmutable::today(),
        );
    }

    public function test_csv_has_header_and_one_row_per_order_in_period(): void
    {
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Guilherme', 'phone' => '11999990000']);

        $order = Order::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $client->id,
            'order_number' => 12,
            'items_total' => 50, 'discount_total' => 5, 'delivery_fee' => 7, 'grand_total' => 52,
            'status' => OrderStatus::Finished, 'origin' => OrderOrigin::Menu,
            'delivery_neighborhood' => 'Centro',
            'opened_at' => now()->subDay(),
        ]);
        OrderPayment::create(['order_id' => $order->id, 'payment_option_name' => 'Pix', 'is_cash' => false, 'amount' => 52]);

        Order::create([
            'tenant_id' => $this->tenant->id, 'order_number' => 5,
            'items_total' => 10, 'grand_total' => 10,
            'status' => OrderStatus::Cancelled, 'cancellation_reason' => CancellationReason::Delay,
            'origin' => OrderOrigin::Staff,
            'opened_at' => now()->subDays(90),
        ]);

        $lines = array_values(array_filter(explode("\n", trim($this->contents()))));

        $this->assertCount(2, $lines); // header + 1 pedido dentro do período
        $this->assertStringContainsString('Numero,ID,Data/Hora', $lines[0]);
        $this->assertStringContainsString('12', $lines[1]);
        $this->assertStringContainsString('Guilherme', $lines[1]);
        $this->assertStringContainsString('Centro', $lines[1]);
        $this->assertStringContainsString('Pix', $lines[1]);
        $this->assertStringContainsString('"52,00"', $lines[1]);
    }

    public function test_csv_is_isolated_between_tenants(): void
    {
        Order::create([
            'tenant_id' => $this->tenant->id, 'order_number' => 1,
            'items_total' => 10, 'grand_total' => 10, 'status' => OrderStatus::Finished,
            'origin' => OrderOrigin::Menu, 'opened_at' => now()->subDay(),
        ]);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511900000000']);
        CurrentTenant::set($other);

        $lines = array_values(array_filter(explode("\n", trim($this->contents()))));

        $this->assertCount(1, $lines); // só o header
    }
}

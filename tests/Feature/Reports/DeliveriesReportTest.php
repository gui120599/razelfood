<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\Reports\DeliveriesReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DeliveriesReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $courierA;

    private User $courierB;

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

        $this->courierA = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ana Entregas']);
        $this->courierB = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bruno Moto']);
    }

    private function makeDelivery(?User $courier, array $attributes = []): Order
    {
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Cliente '.uniqid(), 'phone' => (string) random_int(11000000000, 11999999999)]);

        $order = Order::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'assigned_delivery_user_id' => $courier?->id,
            'items_total' => 40, 'delivery_fee' => 7, 'grand_total' => 47,
            'status' => OrderStatus::Delivered,
            'delivery_neighborhood' => 'Centro',
            'opened_at' => now()->subDays(2)->setTime(19, 0),
            'in_transit_at' => now()->subDays(2)->setTime(19, 30),
            'delivered_at' => now()->subDays(2)->setTime(20, 0),
        ], $attributes));

        OrderPayment::create(['order_id' => $order->id, 'payment_option_name' => 'Pix', 'is_cash' => false, 'amount' => $order->grand_total]);

        return $order;
    }

    private function deliveryGroups(): Collection
    {
        return app(DeliveriesReport::class)->groups(
            CarbonImmutable::today()->subDays(10),
            CarbonImmutable::today(),
        );
    }

    public function test_groups_deliveries_by_courier_with_totals_and_average_time(): void
    {
        $this->makeDelivery($this->courierA, ['grand_total' => 50, 'delivery_fee' => 8]);
        $this->makeDelivery($this->courierA, [
            'grand_total' => 30, 'delivery_fee' => 5,
            'in_transit_at' => now()->subDays(1)->setTime(12, 0),
            'delivered_at' => now()->subDays(1)->setTime(12, 40),
        ]);
        $this->makeDelivery($this->courierB, ['grand_total' => 20, 'delivery_fee' => 6]);

        $groups = $this->deliveryGroups();

        $this->assertSame(['Ana Entregas', 'Bruno Moto'], $groups->pluck('name')->all());

        $ana = $groups->firstWhere('name', 'Ana Entregas');
        $this->assertSame(2, $ana['count']);
        $this->assertSame(80.0, $ana['total']);
        $this->assertSame(13.0, $ana['delivery_fee_total']);
        $this->assertSame(35, $ana['avg_minutes']); // (30 + 40) / 2
        $this->assertCount(2, $ana['orders']);
    }

    public function test_excludes_orders_without_courier_or_not_delivered_or_out_of_period(): void
    {
        $this->makeDelivery($this->courierA);
        $this->makeDelivery(null); // sem entregador
        $this->makeDelivery($this->courierA, ['status' => OrderStatus::InTransit, 'delivered_at' => null]);
        $this->makeDelivery($this->courierA, [
            'delivered_at' => now()->subDays(60),
            'in_transit_at' => now()->subDays(60),
            'opened_at' => now()->subDays(60),
        ]);

        $groups = $this->deliveryGroups();

        $this->assertCount(1, $groups);
        $this->assertSame(1, $groups->first()['count']);
    }

    public function test_is_isolated_between_tenants(): void
    {
        $this->makeDelivery($this->courierA);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511900000000']);
        CurrentTenant::set($other);

        $this->assertTrue($this->deliveryGroups()->isEmpty());
    }
}

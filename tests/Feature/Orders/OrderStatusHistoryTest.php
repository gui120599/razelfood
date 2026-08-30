<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\AdvanceOrderStatus;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\MarkOrderDelivered;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cada transição de status precisa gravar uma linha em order_status_histories
 * (quem, de onde, para onde), sem alterar os timestamps *_at existentes em
 * Order — ambos coexistem (ver plano da Central de Pedidos).
 */
class OrderStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

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
        URL::defaults(['tenant' => $this->tenant->slug]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);
    }

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => $status,
            'opened_at' => now(),
        ]);
    }

    public function test_advance_order_status_records_history_with_acting_user(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = $this->makeOrder(OrderStatus::Started);

        app(AdvanceOrderStatus::class)($order, $user);

        $this->assertDatabaseHas('order_status_histories', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'status_from' => OrderStatus::Started->value,
            'status_to' => OrderStatus::Open->value,
            'user_id' => $user->id,
        ]);
    }

    public function test_cancel_order_records_history_with_reason_note(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = $this->makeOrder(OrderStatus::Open);

        app(CancelOrder::class)($order, CancellationReason::ProductUnavailable, $user);

        $this->assertDatabaseHas('order_status_histories', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'status_from' => OrderStatus::Open->value,
            'status_to' => OrderStatus::Cancelled->value,
            'user_id' => $user->id,
            'note' => CancellationReason::ProductUnavailable->label(),
        ]);
    }

    public function test_mark_order_delivered_records_history(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = $this->makeOrder(OrderStatus::InTransit);

        app(MarkOrderDelivered::class)($order, $user);

        $this->assertDatabaseHas('order_status_histories', [
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'status_from' => OrderStatus::InTransit->value,
            'status_to' => OrderStatus::Delivered->value,
            'user_id' => $user->id,
        ]);
    }

    public function test_history_is_scoped_by_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        $order = $this->makeOrder(OrderStatus::Started);
        app(AdvanceOrderStatus::class)($order);

        CurrentTenant::set($otherTenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);

        $this->assertSame(0, OrderStatusHistory::query()->count());
    }
}

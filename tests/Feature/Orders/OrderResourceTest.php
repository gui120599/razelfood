<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Filament\Tenant\Resources\Orders\Pages\ViewOrder;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

class OrderResourceTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    private function makeOrder(OrderStatus $status, OrderOrigin $origin = OrderOrigin::Menu): Order
    {
        return Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => $status,
            'origin' => $origin,
            'opened_at' => now(),
        ]);
    }

    public function test_list_page_shows_orders_and_filters_by_status(): void
    {
        $open = $this->makeOrder(OrderStatus::Open);
        $cancelled = $this->makeOrder(OrderStatus::Cancelled);

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$open, $cancelled])
            ->filterTable('status', OrderStatus::Cancelled->value)
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_list_page_filters_by_origin(): void
    {
        $fromMenu = $this->makeOrder(OrderStatus::Open, OrderOrigin::Menu);
        $fromStaff = $this->makeOrder(OrderStatus::Open, OrderOrigin::Staff);

        Livewire::test(ListOrders::class)
            ->filterTable('origin', OrderOrigin::Staff->value)
            ->assertCanSeeTableRecords([$fromStaff])
            ->assertCanNotSeeTableRecords([$fromMenu]);
    }

    public function test_view_page_shows_advance_action_for_open_order_and_hides_deliver_action(): void
    {
        $order = $this->makeOrder(OrderStatus::Open);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertOk()
            ->assertActionVisible(TestAction::make('advance'))
            ->assertActionVisible(TestAction::make('cancel'))
            ->assertActionHidden(TestAction::make('markDelivered'));
    }

    public function test_resource_url_resolves_to_order_route(): void
    {
        $order = $this->makeOrder(OrderStatus::Open);

        $this->assertStringContainsString((string) $order->id, OrderResource::getUrl('view', ['record' => $order]));
    }

    public function test_delivery_link_action_renders_signed_url_and_qr_code_for_order_awaiting_dispatch(): void
    {
        $deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 5,
        ]);

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'delivery_option_id' => $deliveryOption->id,
            'items_total' => 40,
            'grand_total' => 45,
            'status' => OrderStatus::Ready,
            'opened_at' => now(),
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertActionVisible(TestAction::make('deliveryLink'))
            ->mountAction(TestAction::make('deliveryLink'))
            ->assertMountedActionModalSee('data:image/svg+xml;base64,', escape: false)
            ->assertMountedActionModalSee('/entrega/'.$order->id, escape: false);
    }

    public function test_delivery_link_action_is_hidden_for_pickup_orders(): void
    {
        $order = $this->makeOrder(OrderStatus::Ready);

        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->assertActionHidden(TestAction::make('deliveryLink'));
    }
}

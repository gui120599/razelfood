<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
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

/**
 * Despacho de pedido Pronto + delivery exige selecionar um entregador
 * (seção 5/16 da spec) — dispatch() encadeia AssignDeliveryUser e
 * AdvanceOrderStatus numa única ação.
 */
class KitchenDispatchTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

    private DeliveryOption $deliveryOption;

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

        $this->deliveryOption = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega padrão',
            'delivery_fee' => 5,
        ]);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cliente Teste',
            'phone' => '11999990000',
        ]);
    }

    private function makeReadyOrder(): Order
    {
        return Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'delivery_option_id' => $this->deliveryOption->id,
            'items_total' => 40,
            'grand_total' => 45,
            'status' => OrderStatus::Ready,
            'opened_at' => now(),
        ]);
    }

    public function test_dispatching_assigns_courier_and_advances_to_in_transit(): void
    {
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');

        $courier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $courier->assignRole('Entregador');

        $order = $this->makeReadyOrder();

        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->callAction(
                TestAction::make('dispatch')->arguments(['order' => $order->id]),
                ['assigned_delivery_user_id' => $courier->id],
            )
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::InTransit, $order->status);
        $this->assertSame($courier->id, $order->assigned_delivery_user_id);
    }

    public function test_dispatching_requires_a_courier_to_be_selected(): void
    {
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');

        $order = $this->makeReadyOrder();

        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->callAction(
                TestAction::make('dispatch')->arguments(['order' => $order->id]),
                ['assigned_delivery_user_id' => null],
            )
            ->assertHasActionErrors(['assigned_delivery_user_id']);
    }

    public function test_advance_action_is_hidden_for_ready_delivery_orders_in_favor_of_dispatch(): void
    {
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');

        $order = $this->makeReadyOrder();

        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertActionVisible(TestAction::make('dispatch')->arguments(['order' => $order->id]));
    }

    public function test_reassign_delivery_changes_the_courier_without_changing_status(): void
    {
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');

        $firstCourier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $firstCourier->assignRole('Entregador');

        $secondCourier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $secondCourier->assignRole('Entregador');

        $order = $this->makeReadyOrder();
        $order->update(['status' => OrderStatus::InTransit, 'assigned_delivery_user_id' => $firstCourier->id]);

        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->callAction(
                TestAction::make('reassignDelivery')->arguments(['order' => $order->id]),
                ['assigned_delivery_user_id' => $secondCourier->id],
            )
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::InTransit, $order->status);
        $this->assertSame($secondCourier->id, $order->assigned_delivery_user_id);
    }

    /**
     * Regressão: uma DeliveryOption "Retirada" (requires_address=false)
     * preenche delivery_option_id normalmente, mas não é entrega de verdade
     * — não pode exigir despacho/entregador nem passar por "Em Transporte".
     */
    public function test_ready_order_with_a_pickup_delivery_option_advances_straight_to_finished(): void
    {
        $pickup = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Retirada',
            'requires_address' => false,
            'delivery_fee' => 0,
        ]);

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'delivery_option_id' => $pickup->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Ready,
            'opened_at' => now(),
        ]);

        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');
        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->assertActionVisible(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertActionHidden(TestAction::make('dispatch')->arguments(['order' => $order->id]))
            ->callAction(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Finished, $order->fresh()->status);
    }
}

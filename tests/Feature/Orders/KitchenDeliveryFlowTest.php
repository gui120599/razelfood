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
 * Configurações de fluxo de entrega por tenant (OrderSettings): quando o tenant
 * não atribui entregador, o pedido de entrega avança sem o passo de despacho;
 * quando não usa a etapa "Em Transporte", vai de "Pronto" direto a "Finalizado".
 */
class KitchenDeliveryFlowTest extends TestCase
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

    private function setTenantFlags(bool $usesTransit, bool $assignsCouriers): void
    {
        $this->tenant->update([
            'uses_in_transit_stage' => $usesTransit,
            'assigns_delivery_couriers' => $assignsCouriers,
        ]);

        CurrentTenant::set($this->tenant);
    }

    private function makeReadyDeliveryOrder(): Order
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

    private function actAsGerente(): User
    {
        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');
        $this->actingAs($gerente);

        return $gerente;
    }

    public function test_without_couriers_a_ready_delivery_order_advances_generically_to_in_transit(): void
    {
        $this->setTenantFlags(usesTransit: true, assignsCouriers: false);
        $this->actAsGerente();
        $order = $this->makeReadyDeliveryOrder();

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('dispatch')->arguments(['order' => $order->id]))
            ->assertActionHidden(TestAction::make('reassignDelivery')->arguments(['order' => $order->id]))
            ->assertActionVisible(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->callAction(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::InTransit, $order->status);
        $this->assertNull($order->assigned_delivery_user_id);
    }

    public function test_without_the_transit_stage_a_ready_delivery_order_advances_straight_to_finished(): void
    {
        $this->setTenantFlags(usesTransit: false, assignsCouriers: false);
        $this->actAsGerente();
        $order = $this->makeReadyDeliveryOrder();

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('dispatch')->arguments(['order' => $order->id]))
            ->assertActionVisible(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->callAction(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Finished, $order->fresh()->status);
    }

    public function test_board_drops_the_in_transit_column_when_the_transit_stage_is_off(): void
    {
        $this->setTenantFlags(usesTransit: false, assignsCouriers: false);

        $this->assertNotContains(OrderStatus::InTransit, (new Kitchen)->boardColumns());
    }

    public function test_board_keeps_the_in_transit_column_by_default(): void
    {
        $this->assertContains(OrderStatus::InTransit, (new Kitchen)->boardColumns());
    }

    public function test_without_couriers_an_attendant_can_confirm_delivery(): void
    {
        $this->setTenantFlags(usesTransit: true, assignsCouriers: false);

        $atendente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $atendente->assignRole('Atendente');
        $this->actingAs($atendente);

        $order = $this->makeReadyDeliveryOrder();
        $order->update(['status' => OrderStatus::InTransit, 'in_transit_at' => now()]);

        Livewire::test(Kitchen::class)
            ->callAction(TestAction::make('markDelivered')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_defaults_keep_the_dispatch_with_courier_flow(): void
    {
        $this->actAsGerente();
        $courier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $courier->assignRole('Entregador');
        $order = $this->makeReadyDeliveryOrder();

        Livewire::test(Kitchen::class)
            ->assertActionVisible(TestAction::make('dispatch')->arguments(['order' => $order->id]))
            ->assertActionHidden(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->callAction(
                TestAction::make('dispatch')->arguments(['order' => $order->id]),
                ['assigned_delivery_user_id' => $courier->id],
            )
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::InTransit, $order->status);
        $this->assertSame($courier->id, $order->assigned_delivery_user_id);
    }
}

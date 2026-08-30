<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\CancellationReason;
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
 * RF-25/RF-26/RN-32: quadro da cozinha e a autorização por papel das ações
 * de transição de status. Usa permissões/papéis reais (via
 * SeedDefaultTenantRoles), sem bypass de Gate — a autorização por papel é
 * exatamente o que está sob teste aqui.
 */
class KitchenOrderStatusFlowTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryOption $deliveryOption;

    private Client $client;

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

    private function makeOrder(OrderStatus $status, bool $withDelivery = true): Order
    {
        return Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'delivery_option_id' => $withDelivery ? $this->deliveryOption->id : null,
            'items_total' => 40,
            'grand_total' => $withDelivery ? 45 : 40,
            'status' => $status,
            'opened_at' => now(),
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_atendente_can_accept_and_advance_but_not_mark_delivered(): void
    {
        $atendente = $this->userWithRole('Atendente');
        $order = $this->makeOrder(OrderStatus::Started);

        $this->actingAs($atendente);

        Livewire::test(Kitchen::class)
            ->callAction(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_from' => OrderStatus::Started->value,
            'status_to' => OrderStatus::Open->value,
            'user_id' => $atendente->id,
        ]);

        $inTransit = $this->makeOrder(OrderStatus::InTransit);

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('markDelivered')->arguments(['order' => $inTransit->id]));
    }

    public function test_entregador_can_only_mark_delivered(): void
    {
        $entregador = $this->userWithRole('Entregador');
        $order = $this->makeOrder(OrderStatus::InTransit);

        $this->actingAs($entregador);

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertActionHidden(TestAction::make('cancel')->arguments(['order' => $order->id]))
            ->callAction(TestAction::make('markDelivered')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_from' => OrderStatus::InTransit->value,
            'status_to' => OrderStatus::Delivered->value,
            'user_id' => $entregador->id,
        ]);
    }

    public function test_pickup_order_skips_in_transit_and_finishes_directly(): void
    {
        $gerente = $this->userWithRole('Gerente');
        $order = $this->makeOrder(OrderStatus::Ready, withDelivery: false);

        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->callAction(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Finished, $order->fresh()->status);
    }

    public function test_cancelling_requires_a_categorized_reason(): void
    {
        $atendente = $this->userWithRole('Atendente');
        $order = $this->makeOrder(OrderStatus::Started);

        $this->actingAs($atendente);

        Livewire::test(Kitchen::class)
            ->callAction(
                TestAction::make('cancel')->arguments(['order' => $order->id]),
                ['reason' => CancellationReason::ProductUnavailable->value],
            )
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancellationReason::ProductUnavailable, $order->cancellation_reason);
        $this->assertSame($atendente->id, $order->cancelled_by_user_id);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_from' => OrderStatus::Started->value,
            'status_to' => OrderStatus::Cancelled->value,
            'user_id' => $atendente->id,
        ]);
    }

    public function test_cancelled_order_can_no_longer_be_advanced_or_cancelled(): void
    {
        $gerente = $this->userWithRole('Gerente');
        $order = $this->makeOrder(OrderStatus::Cancelled);

        $this->actingAs($gerente);

        Livewire::test(Kitchen::class)
            ->assertActionHidden(TestAction::make('advance')->arguments(['order' => $order->id]))
            ->assertActionHidden(TestAction::make('cancel')->arguments(['order' => $order->id]))
            ->assertActionHidden(TestAction::make('markDelivered')->arguments(['order' => $order->id]));
    }

    public function test_user_without_any_order_permission_has_no_grants_and_cannot_open_the_kitchen_page(): void
    {
        $caixa = $this->userWithRole('Caixa');
        $this->actingAs($caixa);

        $this->assertFalse($caixa->can('manage_order_status'));
        $this->assertFalse($caixa->can('mark_order_delivered'));
        $this->assertFalse(Kitchen::canAccess());
    }
}

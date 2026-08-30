<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\AssignDeliveryUser;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Exceptions\OrderTransitionException;
use App\Models\Client;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssignDeliveryUserTest extends TestCase
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

    private function makeOrder(): Order
    {
        return Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Ready,
            'opened_at' => now(),
        ]);
    }

    public function test_assigns_delivery_user_from_the_same_tenant_with_entregador_role(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $courier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $courier->assignRole('Entregador');

        $order = $this->makeOrder();

        app(AssignDeliveryUser::class)($order, $courier, $manager);

        $this->assertSame($courier->id, $order->fresh()->assigned_delivery_user_id);
    }

    public function test_rejects_delivery_user_from_another_tenant(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $order = $this->makeOrder();

        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988888888',
        ]);

        $courierFromOtherTenant = User::factory()->create(['tenant_id' => $otherTenant->id]);
        // Role em outro "time" — não visível aqui, mas o teste cobre a checagem de tenant_id
        // independentemente da role, que é a primeira barreira em AssignDeliveryUser.

        $this->expectException(OrderTransitionException::class);

        app(AssignDeliveryUser::class)($order, $courierFromOtherTenant, $manager);
    }

    public function test_rejects_user_without_entregador_role(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $attendant = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $attendant->assignRole('Atendente');

        $order = $this->makeOrder();

        $this->expectException(OrderTransitionException::class);

        app(AssignDeliveryUser::class)($order, $attendant, $manager);
    }

    public function test_delivery_personnel_scope_only_returns_couriers_from_the_current_tenant(): void
    {
        $courier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $courier->assignRole('Entregador');

        $otherTenant = Tenant::create([
            'name' => 'Outro Tenant',
            'slug' => 'outro-tenant-2',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511977777777',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        app(SeedDefaultTenantRoles::class)($otherTenant);
        $otherCourier = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherCourier->assignRole('Entregador');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $results = User::deliveryPersonnel()->pluck('id');

        $this->assertTrue($results->contains($courier->id));
        $this->assertFalse($results->contains($otherCourier->id));
    }
}

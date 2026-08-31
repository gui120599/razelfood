<?php

namespace Tests\Feature\Reports;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Deliveries;
use App\Models\Client;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Versões imprimíveis (A4) dos relatórios de pedidos e de entregas, e a
 * página Entregas em si (RF-31).
 */
class ReportPrintPagesTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Empório da Pizza', 'slug' => 'tenant-teste',
            'status' => TenantStatus::Active, 'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function seedOrders(): void
    {
        $courier = $this->userWithRole('Entregador');
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Guilherme', 'phone' => '11999990000']);

        Order::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $client->id, 'order_number' => 7,
            'assigned_delivery_user_id' => $courier->id,
            'items_total' => 40, 'delivery_fee' => 7, 'grand_total' => 47,
            'status' => OrderStatus::Delivered, 'delivery_neighborhood' => 'Centro',
            'opened_at' => now()->subDay(), 'in_transit_at' => now()->subDay(), 'delivered_at' => now()->subDay(),
        ]);
    }

    public function test_orders_print_requires_permission_and_renders(): void
    {
        $this->seedOrders();
        $url = route('reports.orders.print', ['start' => now()->subDays(30)->toDateString(), 'end' => now()->toDateString()]);

        $this->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole('Atendente'))->get($url)->assertForbidden();

        $this->actingAs($this->userWithRole('Gerente'))->get($url)
            ->assertOk()
            ->assertSee('Relatório de Pedidos')
            ->assertSee('Guilherme')
            ->assertSee('window.print()', false);
    }

    public function test_deliveries_print_requires_permission_and_groups_by_courier(): void
    {
        $this->seedOrders();
        $url = route('reports.deliveries.print', ['start' => now()->subDays(30)->toDateString(), 'end' => now()->toDateString()]);

        $this->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole('Atendente'))->get($url)->assertForbidden();

        $this->actingAs($this->userWithRole('Gerente'))->get($url)
            ->assertOk()
            ->assertSee('Relatório de Entregas por Entregador')
            ->assertSee('Centro');
    }

    public function test_print_reports_show_the_logo_only_when_enabled(): void
    {
        $this->seedOrders();
        $this->tenant->update(['print_logo_path' => 'tenants/print/logo.png']);
        $url = route('reports.orders.print', ['start' => now()->subDays(30)->toDateString(), 'end' => now()->toDateString()]);

        $this->actingAs($this->userWithRole('Gerente'))->get($url)
            ->assertOk()
            ->assertDontSee('tenants/print/logo.png');

        $this->tenant->update(['show_logo_on_prints' => true]);

        $this->actingAs($this->userWithRole('Gerente'))->get($url)
            ->assertOk()
            ->assertSee('tenants/print/logo.png');
    }

    public function test_deliveries_page_access_gate(): void
    {
        $this->actingAs($this->userWithRole('Gerente'));
        $this->assertTrue(Deliveries::canAccess());
        Livewire::test(Deliveries::class)->assertOk();

        $this->actingAs($this->userWithRole('Atendente'));
        $this->assertFalse(Deliveries::canAccess());
    }
}

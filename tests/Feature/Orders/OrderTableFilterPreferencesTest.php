<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Models\Client;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

class OrderTableFilterPreferencesTest extends TestCase
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

    public function test_filtering_the_table_persists_the_filter_as_a_preference(): void
    {
        Livewire::test(ListOrders::class)->filterTable('status', OrderStatus::Cancelled->value);

        $saved = UserPreference::valueFor($this->admin, 'orders.table_filters');

        $this->assertSame(OrderStatus::Cancelled->value, $saved['tableFilters']['status']['value'] ?? null);
    }

    public function test_a_fresh_page_load_restores_the_saved_table_filter(): void
    {
        $cancelled = $this->makeOrder(OrderStatus::Cancelled);
        $open = $this->makeOrder(OrderStatus::Open);

        Livewire::test(ListOrders::class)->filterTable('status', OrderStatus::Cancelled->value);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$open]);
    }
}

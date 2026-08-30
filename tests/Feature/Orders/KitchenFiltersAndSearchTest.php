<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\Client;
use App\Models\DeliveryOption;
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

class KitchenFiltersAndSearchTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

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

        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');
        $this->actingAs($gerente);
    }

    private function makeOrder(array $overrides = []): Order
    {
        $client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => $overrides['client_name'] ?? 'Cliente Teste',
            'phone' => $overrides['client_phone'] ?? fake()->unique()->numerify('119########'),
        ]);

        $attributes = collect($overrides)->except(['client_name', 'client_phone'])->all();

        return Order::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'delivery_option_id' => null,
            'origin' => OrderOrigin::Menu,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
        ], $attributes));
    }

    private function flattenIds(Kitchen $page): array
    {
        return $page->ordersByStatus()->flatten()->pluck('id')->sort()->values()->all();
    }

    public function test_search_matches_by_order_number(): void
    {
        $target = $this->makeOrder();
        // Telefone fixo sem dígitos repetidos que possam coincidir por acaso com o ID
        // do pedido (a busca por termo numérico também casa por telefone — RN da spec).
        $this->makeOrder(['client_phone' => '00000000000']);

        $page = Livewire::test(Kitchen::class)->set('search', (string) $target->id)->instance();

        $this->assertSame([$target->id], $this->flattenIds($page));
    }

    public function test_search_matches_by_partial_client_name(): void
    {
        $target = $this->makeOrder(['client_name' => 'Maria da Silva']);
        $this->makeOrder(['client_name' => 'João Pereira']);

        $page = Livewire::test(Kitchen::class)->set('search', 'Maria')->instance();

        $this->assertSame([$target->id], $this->flattenIds($page));
    }

    public function test_search_matches_by_partial_phone(): void
    {
        $target = $this->makeOrder(['client_phone' => '11955557777']);
        $this->makeOrder(['client_phone' => '11944443333']);

        $page = Livewire::test(Kitchen::class)->set('search', '5555')->instance();

        $this->assertSame([$target->id], $this->flattenIds($page));
    }

    public function test_quick_filter_delivery_only_shows_orders_with_delivery_option(): void
    {
        $delivery = $this->makeOrder(['delivery_option_id' => $this->deliveryOption->id]);
        $this->makeOrder(['delivery_option_id' => null]);

        $page = Livewire::test(Kitchen::class)->set('quickFilter', 'delivery')->instance();

        $this->assertSame([$delivery->id], $this->flattenIds($page));
    }

    public function test_quick_filter_dine_in_only_shows_table_orders(): void
    {
        $dineIn = $this->makeOrder(['origin' => OrderOrigin::Table]);
        $this->makeOrder(['origin' => OrderOrigin::Menu]);

        $page = Livewire::test(Kitchen::class)->set('quickFilter', 'dine_in')->instance();

        $this->assertSame([$dineIn->id], $this->flattenIds($page));
    }

    public function test_quick_filter_preparing_only_shows_preparing_orders(): void
    {
        $preparing = $this->makeOrder(['status' => OrderStatus::Preparing]);
        $this->makeOrder(['status' => OrderStatus::Open]);

        $page = Livewire::test(Kitchen::class)->set('quickFilter', 'preparing')->instance();

        $this->assertSame([$preparing->id], $this->flattenIds($page));
    }

    public function test_only_late_toggle_shows_orders_past_the_tenant_late_threshold(): void
    {
        $this->tenant->update(['order_attention_after_minutes' => 15, 'order_late_after_minutes' => 30]);
        CurrentTenant::set($this->tenant->fresh());

        $late = $this->makeOrder(['status' => OrderStatus::Preparing, 'opened_at' => now()->subMinutes(45), 'preparing_at' => now()->subMinutes(45)]);
        $this->makeOrder(['status' => OrderStatus::Preparing, 'opened_at' => now()->subMinutes(5), 'preparing_at' => now()->subMinutes(5)]);

        $page = Livewire::test(Kitchen::class)->set('onlyLate', true)->instance();

        $this->assertSame([$late->id], $this->flattenIds($page));
    }
}

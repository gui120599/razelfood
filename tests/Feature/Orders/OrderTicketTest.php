<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Comanda de cozinha imprimível — protegida por auth + permissão
 * `manage_order_status` (App\Http\Controllers\OrderTicketController).
 */
class OrderTicketTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Empório da Pizza',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511988887777',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Guilherme',
            'phone' => '11999990000',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeOrder(): Order
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        $deliveryOption = DeliveryOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Entrega', 'delivery_fee' => 7, 'requires_address' => true]);

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'order_number' => 438,
            'client_id' => $this->client->id,
            'delivery_option_id' => $deliveryOption->id,
            'items_total' => 51,
            'discount_total' => 0,
            'delivery_fee' => 7,
            'grand_total' => 58,
            'status' => OrderStatus::Preparing,
            'delivery_address' => 'Rua A 100 - Centro, Cidade - SP',
            'delivery_neighborhood' => 'Centro',
            'notes' => 'Sem cebola',
            'opened_at' => now(),
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'payment_option_name' => 'Dinheiro',
            'is_cash' => true,
            'amount' => 58,
            'change_for' => 100,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $flavorA->id,
            'quantity' => 1,
            'unit_price' => 45,
            'original_unit_price' => 45,
            'flavors' => [$flavorA->id, $flavorB->id],
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => $flavorA->id, 'target_share' => 0.5, 'unit_cost' => 3]],
            'addons_total' => 3,
        ]);

        return $order;
    }

    public function test_guest_cannot_open_ticket(): void
    {
        $order = $this->makeOrder();

        $this->get(route('order.ticket', $order))->assertForbidden();
    }

    public function test_user_without_manage_order_status_permission_cannot_open_ticket(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->userWithRole('Caixa'))
            ->get(route('order.ticket', $order))
            ->assertForbidden();
    }

    public function test_user_from_another_tenant_cannot_open_ticket(): void
    {
        $order = $this->makeOrder();
        $otherTenant = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511900000000']);
        $outsider = User::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($outsider)
            ->get(route('order.ticket', $order))
            ->assertForbidden();
    }

    public function test_ticket_for_order_of_another_tenant_returns_404(): void
    {
        $otherTenant = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511900000000']);
        $foreignOrder = Order::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'order_number' => 1,
            'items_total' => 10, 'grand_total' => 10,
            'status' => OrderStatus::Preparing, 'opened_at' => now(),
        ]);

        $this->actingAs($this->userWithRole('Atendente'))
            ->get(route('order.ticket', $foreignOrder))
            ->assertNotFound();
    }

    public function test_ticket_renders_order_details(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->userWithRole('Atendente'))
            ->get(route('order.ticket', $order))
            ->assertOk()
            ->assertSee('COMANDA #438')
            ->assertSee('Empório da Pizza')
            ->assertSee('Guilherme')
            ->assertSee('PIZZAS')
            ->assertSee('Calabresa / Marguerita')
            ->assertSee('1x Bacon extra (Calabresa)')
            ->assertSee('Sem cebola')
            ->assertSee('R$ 58,00')
            ->assertSee('Troco para R$ 100,00')
            ->assertSee('Troco: R$ 42,00')
            ->assertSee('Centro')
            ->assertSee('(11) 99999-0000')
            ->assertSee('+55 (11) 98888-7777');
    }

    public function test_ticket_shows_the_print_logo_only_when_enabled(): void
    {
        $order = $this->makeOrder();
        $this->tenant->update([
            'print_logo_path' => 'tenants/print/logo.png',
            'show_logo_on_prints' => false,
        ]);

        $this->actingAs($this->userWithRole('Atendente'))
            ->get(route('order.ticket', $order))
            ->assertOk()
            ->assertDontSee('tenants/print/logo.png');

        $this->tenant->update(['show_logo_on_prints' => true]);

        $this->actingAs($this->userWithRole('Atendente'))
            ->get(route('order.ticket', $order))
            ->assertOk()
            ->assertSee('tenants/print/logo.png');
    }
}

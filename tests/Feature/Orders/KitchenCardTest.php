<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\CancellationReason;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\Category;
use App\Models\Client;
use App\Models\DeliveryOption;
use App\Models\FlavorQuantityOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Visual do kanban da Central de Pedidos (Fase 1.S.2a), copiado do Painel de Pedidos original:
 * card completo, menu de ações por permissão, cabeçalhos de coluna e rótulos dos filtros.
 */
class KitchenCardTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryOption $delivery;

    private Category $pizzas;

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

        $this->delivery = DeliveryOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entrega em casa',
            'requires_address' => true,
            'delivery_fee' => 5,
        ]);

        $this->pizzas = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);

        $this->actingAsRole('Gerente');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function makeOrder(array $overrides = []): Order
    {
        // Telefone único por cliente (índice único por tenant); o primeiro fica com o número fixo dos asserts.
        $client = Client::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Maria da Silva',
            'phone' => Client::query()->exists() ? fake()->unique()->numerify('119########') : '11988887777',
        ]);

        return Order::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'delivery_option_id' => $this->delivery->id,
            'origin' => OrderOrigin::Menu,
            'items_total' => 40,
            'grand_total' => 45,
            'status' => OrderStatus::Open,
            'opened_at' => now(),
            'delivery_address' => 'Rua das Flores, 100 — Centro',
        ], $overrides));
    }

    private function addItem(Order $order, Product $product, array $overrides = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 20,
            'original_unit_price' => $overrides['unit_price'] ?? 20,
            'addons_total' => 0,
        ], $overrides));
    }

    private function makeProduct(string $name, float $price = 20, ?Category $category = null): Product
    {
        return Product::create(['tenant_id' => $this->tenant->id, 'category_id' => ($category ?? $this->pizzas)->id, 'name' => $name, 'price' => $price]);
    }

    public function test_card_shows_the_whole_order_context(): void
    {
        $order = $this->makeOrder(['assigned_delivery_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id]);
        $this->addItem($order, $this->makeProduct('Calabresa'), ['note' => 'sem cebola', 'addons_total' => 3]);
        $this->addItem($order, $this->makeProduct('Refrigerante'), ['quantity' => 1, 'unit_price' => 8]);
        $this->addItem($order, $this->makeProduct('Suco'), ['quantity' => 1, 'unit_price' => 9]);

        Livewire::test(Kitchen::class)
            ->assertSee('Entrega em casa')
            ->assertSee('Maria da Silva')
            ->assertSee('11988887777')
            ->assertSee('Rua das Flores, 100')
            ->assertSee('Calabresa')
            ->assertSee('Refrigerante')
            ->assertSee('Suco')
            ->assertSee('OBS: sem cebola')
            ->assertSee('R$ 46,00')
            ->assertSee('R$ 8,00')
            ->assertSee('R$ 9,00')
            // O total do pedido fica no modal de detalhes, não no card (como na tela original).
            ->assertDontSee('R$ 45,00');
    }

    public function test_card_marks_an_order_already_charged_in_a_sale(): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'number' => 7, 'status' => SaleStatus::Finalized,
            'items_total' => 45, 'total' => 45, 'finalized_at' => now(),
        ]);
        $paid = $this->makeOrder();
        $this->addItem($paid, $this->makeProduct('Calabresa'));
        Order::query()->whereKey($paid->id)->update(['sale_id' => $sale->id]);

        Livewire::test(Kitchen::class)->assertSee('Já pago')->assertSee('#7');
    }

    public function test_half_and_half_pizza_shows_one_line_per_flavor_with_the_fraction(): void
    {
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $a = $this->makeProduct('Calabresa', 40);
        $b = $this->makeProduct('Marguerita', 50);
        $order = $this->makeOrder();
        $this->addItem($order, $a, ['flavors' => [$a->id, $b->id], 'quantity' => 1, 'unit_price' => 45]);

        Livewire::test(Kitchen::class)
            ->assertSee('MEIA Calabresa')
            ->assertSee('MEIA Marguerita');
    }

    public function test_custom_flavor_shares_come_from_the_category_option(): void
    {
        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'label' => 'Dois sabores',
            'flavor_count' => 2, 'display_order' => 1, 'flavor_shares' => [70, 30],
        ]);
        $a = $this->makeProduct('Calabresa', 40);
        $b = $this->makeProduct('Marguerita', 50);
        $order = $this->makeOrder();
        $this->addItem($order, $a, ['flavors' => [$a->id, $b->id], 'quantity' => 1]);

        Livewire::test(Kitchen::class)
            ->assertSee('70% Calabresa')
            ->assertSee('30% Marguerita')
            ->assertDontSee('MEIA Calabresa');
    }

    public function test_menu_exposes_delivery_link_and_courier_reassignment_only_when_they_apply(): void
    {
        $ready = $this->makeOrder(['status' => OrderStatus::Ready]);
        $this->addItem($ready, $this->makeProduct('Calabresa'));
        $open = $this->makeOrder(['status' => OrderStatus::Open]);
        $this->addItem($open, $this->makeProduct('Marguerita'));

        $component = Livewire::test(Kitchen::class);

        $component->assertSeeHtml("mountAction('deliveryLink', { order: {$ready->id} })")
            ->assertSeeHtml("mountAction('reassignDelivery', { order: {$ready->id} })")
            ->assertDontSeeHtml("mountAction('deliveryLink', { order: {$open->id} })")
            ->assertDontSeeHtml("mountAction('reassignDelivery', { order: {$open->id} })");
    }

    public function test_menu_hides_courier_reassignment_when_the_tenant_does_not_assign_couriers(): void
    {
        $this->tenant->update(['assigns_delivery_couriers' => false]);
        CurrentTenant::set($this->tenant->fresh());

        $ready = $this->makeOrder(['status' => OrderStatus::Ready]);
        $this->addItem($ready, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)
            ->assertDontSeeHtml("mountAction('reassignDelivery', { order: {$ready->id} })")
            ->assertSeeHtml("mountAction('deliveryLink', { order: {$ready->id} })");
    }

    public function test_menu_offers_the_tracking_link_with_the_public_token_and_the_attendance_shortcut(): void
    {
        $order = $this->makeOrder();
        $this->addItem($order, $this->makeProduct('Calabresa'));
        $order->refresh();

        Livewire::test(Kitchen::class)
            ->assertSee('Copiar link de acompanhamento')
            ->assertSeeHtml($order->public_token)
            ->assertDontSeeHtml("acompanhar\\/{$order->id}\"")
            ->assertSee('Abrir no atendimento')
            ->assertSee('Imprimir pedido');
    }

    public function test_courier_only_sees_the_details_entry_and_the_confirm_delivery_button(): void
    {
        $this->actingAsRole('Entregador');
        $order = $this->makeOrder(['status' => OrderStatus::InTransit]);
        $this->addItem($order, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)
            ->assertSee('Ver detalhes')
            ->assertSee('Confirmar entrega')
            ->assertDontSee('Copiar link de acompanhamento')
            ->assertDontSee('Abrir no atendimento')
            ->assertDontSee('Trocar entregador')
            ->assertDontSee('Link de entrega');
    }

    public function test_started_order_is_confirmed_and_rejected_while_preparing_is_cancelled(): void
    {
        $started = $this->makeOrder(['status' => OrderStatus::Started]);
        $this->addItem($started, $this->makeProduct('Calabresa'));
        $preparing = $this->makeOrder(['status' => OrderStatus::Preparing]);
        $this->addItem($preparing, $this->makeProduct('Marguerita'));

        $page = Livewire::test(Kitchen::class)
            ->assertSee('Confirmar')
            ->assertSee('Rejeitar pedido')
            ->assertSee('Cancelar pedido')
            ->instance();

        $this->assertSame('Confirmar', $page->primaryActionLabel($started));
        $this->assertSame('Rejeitar', $page->cancelLabel($started));
        $this->assertSame('Cancelar', $page->cancelLabel($preparing));
    }

    public function test_columns_have_a_label_a_counter_and_the_filters_use_the_original_labels(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::Preparing]);
        $this->addItem($order, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)
            ->assertSee('Novos')
            ->assertSee('Aceitos')
            ->assertSee('Preparando')
            ->assertSee('Prontos')
            ->assertSee('Em Entrega')
            ->assertSee('Finalizados')
            ->assertSee('Nenhum pedido aguardando.')
            ->assertSee('Só atrasados')
            ->assertSeeHtml('<option value="dine_in">Mesa</option>')
            ->assertSeeHtml('<option value="pending">Novos</option>')
            ->assertSeeHtml('id="coluna-preparing"');
    }

    public function test_in_transit_column_disappears_when_the_tenant_skips_that_stage(): void
    {
        Livewire::test(Kitchen::class)->assertSeeHtml('id="coluna-in-transit"');

        $this->tenant->update(['uses_in_transit_stage' => false]);
        CurrentTenant::set($this->tenant->fresh());

        Livewire::test(Kitchen::class)
            ->assertDontSeeHtml('id="coluna-in-transit"')
            ->assertSeeHtml('id="coluna-delivered"')
            ->assertSeeHtml('--painel-colunas: 5');
    }

    public function test_cancelled_strip_shows_the_reason_and_who_cancelled(): void
    {
        $canceller = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Joana Gerente']);
        $order = $this->makeOrder([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => CancellationReason::cases()[0],
            'cancelled_by_user_id' => $canceller->id,
        ]);
        $this->addItem($order, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)
            ->assertDontSee('Cancelados neste turno')
            ->set('showCancelled', true)
            ->assertSee('Cancelados neste turno')
            ->assertSee(CancellationReason::cases()[0]->label())
            ->assertSee('Por: Joana Gerente');
    }

    public function test_board_never_shows_orders_or_clients_from_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511888888888']);
        $otherClient = Client::create(['tenant_id' => $other->id, 'name' => 'Cliente Alheio', 'phone' => '11900000000']);
        Order::create([
            'tenant_id' => $other->id, 'client_id' => $otherClient->id, 'origin' => OrderOrigin::Menu, 'items_total' => 10,
            'grand_total' => 10, 'status' => OrderStatus::Open, 'opened_at' => now(),
        ]);

        $mine = $this->makeOrder();
        $this->addItem($mine, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)
            ->assertSee('Maria da Silva')
            ->assertDontSee('Cliente Alheio');
    }

    public function test_query_count_does_not_grow_with_the_number_of_orders(): void
    {
        $product = $this->makeProduct('Calabresa');
        $this->seedOrders(2, $product);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Kitchen::class);
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->seedOrders(10, $product);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Kitchen::class);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($few + 2, $many, "queries: {$few} com 2 pedidos × {$many} com 12 pedidos");
    }

    public function test_attendant_only_gets_the_attendance_shortcut_where_the_edit_page_would_not_return_403(): void
    {
        $this->actingAsRole('Atendente');

        $open = $this->makeOrder(['status' => OrderStatus::Open]);
        $this->addItem($open, $this->makeProduct('Calabresa'));
        $ready = $this->makeOrder(['status' => OrderStatus::Ready]);
        $this->addItem($ready, $this->makeProduct('Marguerita'));

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'number' => 9, 'status' => SaleStatus::Finalized,
            'items_total' => 45, 'total' => 45, 'finalized_at' => now(),
        ]);
        $charged = $this->makeOrder(['status' => OrderStatus::Open]);
        $this->addItem($charged, $this->makeProduct('Suco'));
        Order::query()->whereKey($charged->id)->update(['sale_id' => $sale->id]);

        $component = Livewire::test(Kitchen::class);

        $component->assertSeeHtml("pedidos/atender/{$open->id}\"")
            ->assertDontSeeHtml("pedidos/atender/{$ready->id}\"")
            ->assertDontSeeHtml("pedidos/atender/{$charged->id}\"");

        $page = $component->instance();
        $this->assertTrue($open->fresh()->isEditableInAttendanceBy(auth()->user()));
        $this->assertFalse($ready->fresh()->isEditableInAttendanceBy(auth()->user()));
        $this->assertFalse($charged->fresh()->isEditableInAttendanceBy(auth()->user()));
        $this->assertNotNull($page);
    }

    public function test_manager_with_advanced_permission_can_open_a_ready_order_in_attendance(): void
    {
        $ready = $this->makeOrder(['status' => OrderStatus::Ready]);
        $this->addItem($ready, $this->makeProduct('Calabresa'));

        $this->assertTrue($ready->fresh()->isEditableInAttendanceBy(auth()->user()));
    }

    public function test_attendant_can_confirm_delivery_only_when_the_tenant_does_not_assign_couriers(): void
    {
        $this->actingAsRole('Atendente');
        $order = $this->makeOrder(['status' => OrderStatus::InTransit]);
        $this->addItem($order, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)->assertDontSee('Confirmar entrega');

        $this->tenant->update(['assigns_delivery_couriers' => false]);
        CurrentTenant::set($this->tenant->fresh());

        Livewire::test(Kitchen::class)->assertSee('Confirmar entrega');
    }

    public function test_flavor_shares_survive_a_soft_deleted_category(): void
    {
        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'label' => 'Dois sabores',
            'flavor_count' => 2, 'display_order' => 1, 'flavor_shares' => [70, 30],
        ]);
        $a = $this->makeProduct('Calabresa', 40);
        $b = $this->makeProduct('Marguerita', 50);
        $order = $this->makeOrder();
        $this->addItem($order, $a, ['flavors' => [$a->id, $b->id], 'quantity' => 1]);
        $this->pizzas->delete();

        Livewire::test(Kitchen::class)->assertSee('70% Calabresa')->assertSee('30% Marguerita');
    }

    public function test_cancelled_cards_are_keyed_for_the_poll_morph(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
        $this->addItem($order, $this->makeProduct('Calabresa'));

        Livewire::test(Kitchen::class)->set('showCancelled', true)->assertSeeHtml("wire:key=\"cancelled-{$order->id}\"");
    }

    private function seedOrders(int $count, Product $product): void
    {
        for ($i = 0; $i < $count; $i++) {
            $order = $this->makeOrder(['status' => [OrderStatus::Started, OrderStatus::Open, OrderStatus::Preparing, OrderStatus::Ready][$i % 4]]);
            $this->addItem($order, $product, ['note' => 'obs '.$i]);
        }
    }
}

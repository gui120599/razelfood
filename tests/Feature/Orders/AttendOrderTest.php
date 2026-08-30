<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateOrderFromCart;
use App\Actions\Orders\UpdateOrderFromCart;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Pages\Orders\AttendOrder;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Client;
use App\Models\FlavorQuantityOption;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\ProductAddon;
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
 * Workspace de atendimento (criar/editar pedido pelo painel). Cobre só o
 * que é novo desta feature — regras de negócio de preço/estoque/promoção
 * já testadas via CreateOrderFromCart/Checkout não são repetidas aqui.
 */
class AttendOrderTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private PaymentOption $paymentOption;

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

        // Sem nenhum BusinessHour ativo = cardápio "fechado" o tempo todo —
        // prova que o atendente consegue lançar pedido mesmo assim.
        $this->paymentOption = PaymentOption::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cartão',
            'is_cash' => false,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_attendant_creates_order_with_simple_and_combo_items_even_with_business_closed(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $simpleCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $simpleProduct = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $simpleCategory->id, 'name' => 'Refrigerante', 'price' => 8]);

        $comboCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 2, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Marguerita', 'price' => 50]);

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $simpleProduct->id)
            ->call('addConfirmedLine', [
                'type' => 'combo',
                'product_id' => $flavorA->id,
                'flavor_ids' => [$flavorA->id, $flavorB->id],
                'quantity' => 1,
                'note' => null,
            ])
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => null, 'change_for' => null]],
            ])
            ->call('save')
            ->assertSet('errorMessage', null);

        $this->assertSame(1, Order::count());

        $order = Order::first();
        $this->assertSame(OrderOrigin::Staff, $order->origin);
        $this->assertSame(2, $order->items()->count());

        $comboItem = $order->items()->whereNotNull('flavors')->first();
        $this->assertNotNull($comboItem);
        $this->assertEqualsCanonicalizing([$flavorA->id, $flavorB->id], $comboItem->flavors);
        $this->assertSame('45.00', $comboItem->unit_price); // média de 40 e 50
    }

    public function test_creation_is_blocked_when_stock_is_insufficient(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Água',
            'price' => 5,
            'controls_stock' => true,
            'stock_quantity' => 1,
        ]);

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id)
            ->call('updateItemQuantity', 0, 5)
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => null, 'change_for' => null]],
            ])
            ->call('save');

        $this->assertSame(0, Order::count());
        $this->assertSame('1.00', $product->fresh()->stock_quantity);
    }

    public function test_editing_order_replaces_items_recalculates_totals_and_reverts_old_stock(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $oldProduct = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Suco',
            'price' => 10, 'controls_stock' => true, 'stock_quantity' => 20,
        ]);
        $newProduct = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Água',
            'price' => 6, 'controls_stock' => true, 'stock_quantity' => 20,
        ]);

        $order = app(CreateOrderFromCart::class)(
            [['type' => 'simple', 'product_id' => $oldProduct->id, 'flavor_ids' => [], 'quantity' => 2, 'note' => null]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 20.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );

        $this->assertSame('18.00', $oldProduct->fresh()->stock_quantity);

        $this->actingAs($this->userWithRole('Atendente'));

        Livewire::test(AttendOrder::class, ['order' => $order->id])
            ->assertSet('cartItems', [
                ['type' => 'simple', 'product_id' => $oldProduct->id, 'flavor_ids' => [], 'quantity' => 2, 'note' => null, 'addons' => []],
            ])
            ->call('removeItem', 0)
            ->call('addSimpleItem', $newProduct->id)
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => null, 'change_for' => null]],
            ])
            ->call('save')
            ->assertSet('errorMessage', null);

        $order->refresh();

        $this->assertSame(1, $order->items()->count());
        $this->assertSame($newProduct->id, $order->items()->first()->product_id);
        $this->assertSame('6.00', $order->items_total);
        $this->assertSame('6.00', $order->grand_total);

        // Reverteu o estoque do produto antigo e decrementou o novo.
        $this->assertSame('20.00', $oldProduct->fresh()->stock_quantity);
        $this->assertSame('19.00', $newProduct->fresh()->stock_quantity);
    }

    public function test_combo_checkout_splits_stock_and_sales_count_proportionally_between_flavors(): void
    {
        $comboCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);

        $flavorA = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Calabresa',
            'price' => 40, 'controls_stock' => true, 'stock_quantity' => 10,
        ]);
        $flavorB = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Marguerita',
            'price' => 50, 'controls_stock' => true, 'stock_quantity' => 10,
        ]);

        app(CreateOrderFromCart::class)(
            [['type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 45.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );

        // 1 pizza com 2 sabores debita 0,5 do estoque e soma 0,5 em vendas
        // de CADA sabor (rateio proporcional), não 1 unidade cheia de cada.
        $this->assertSame('9.50', $flavorA->fresh()->stock_quantity);
        $this->assertSame('9.50', $flavorB->fresh()->stock_quantity);
        $this->assertSame('0.50', $flavorA->fresh()->sales_count);
        $this->assertSame('0.50', $flavorB->fresh()->sales_count);
    }

    public function test_combo_checkout_respects_custom_configured_flavor_shares_not_equal_division(): void
    {
        $comboCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'label' => 'Três sabores',
            'flavor_count' => 3, 'display_order' => 1, 'flavor_shares' => [60, 30, 10],
        ]);

        $flavorA = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Calabresa',
            'price' => 40, 'controls_stock' => true, 'stock_quantity' => 10,
        ]);
        $flavorB = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Marguerita',
            'price' => 40, 'controls_stock' => true, 'stock_quantity' => 10,
        ]);
        $flavorC = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Frango',
            'price' => 40, 'controls_stock' => true, 'stock_quantity' => 10,
        ]);

        app(CreateOrderFromCart::class)(
            [['type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id, $flavorC->id], 'quantity' => 2, 'note' => null]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 80.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );

        // Rateio configurado (60/30/10), não divisão igualitária — e a soma
        // debitada bate exatamente com a quantidade do pedido (2 pizzas),
        // sem resíduo de arredondamento.
        $this->assertSame('8.80', $flavorA->fresh()->stock_quantity); // 10 - (2 * 0,60)
        $this->assertSame('9.40', $flavorB->fresh()->stock_quantity); // 10 - (2 * 0,30)
        $this->assertSame('9.80', $flavorC->fresh()->stock_quantity); // 10 - (2 * 0,10)
    }

    public function test_addon_stock_and_sales_count_split_by_target_share_on_combo_checkout(): void
    {
        $comboCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);

        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $comboCategory->id, 'name' => 'Marguerita', 'price' => 50]);

        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6, 'controls_stock' => true, 'stock_quantity' => 10]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        app(CreateOrderFromCart::class)(
            [[
                'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
                'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => $flavorA->id]],
            ]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 48.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );

        // Alvo = só a Calabresa (50% do combo): 1 porção do adicional debita
        // 0,5 do estoque e soma 0,5 em vendas — mesmo rateio de flavor_shares.
        $this->assertSame('9.50', $addon->fresh()->stock_quantity);
        $this->assertSame('0.50', $addon->fresh()->sales_count);
    }

    public function test_addon_checkout_blocked_when_addon_stock_insufficient(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1, 'controls_stock' => true, 'stock_quantity' => 2]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AttendOrder::class)
            ->call('addConfirmedLine', [
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
                'addons' => [['addon_id' => $addon->id, 'quantity' => 5, 'target' => null]],
            ])
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => null, 'change_for' => null]],
            ])
            ->call('save');

        $this->assertSame(0, Order::count());
        $this->assertSame('2.00', $addon->fresh()->stock_quantity);
    }

    public function test_editing_order_reverts_and_reapplies_addon_stock(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $oldAddon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1, 'controls_stock' => true, 'stock_quantity' => 10]);
        $newAddon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Limão extra', 'price' => 1, 'controls_stock' => true, 'stock_quantity' => 10]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $oldAddon->id]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $newAddon->id]);

        $order = app(CreateOrderFromCart::class)(
            [[
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
                'addons' => [['addon_id' => $oldAddon->id, 'quantity' => 3, 'target' => null]],
            ]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 11.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );

        $this->assertSame('7.00', $oldAddon->fresh()->stock_quantity);

        app(UpdateOrderFromCart::class)(
            $order,
            [[
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
                'addons' => [['addon_id' => $newAddon->id, 'quantity' => 2, 'target' => null]],
            ]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 10.0, 'change_for' => null]],
            ],
        );

        // Reverteu o estoque do adicional antigo e decrementou o novo.
        $this->assertSame('10.00', $oldAddon->fresh()->stock_quantity);
        $this->assertSame('8.00', $newAddon->fresh()->stock_quantity);
    }

    public function test_order_item_persists_raw_addons_json_and_snapshotted_addons_total(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 2]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        $order = app(CreateOrderFromCart::class)(
            [[
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
                'addons' => [['addon_id' => $addon->id, 'quantity' => 2, 'target' => null]],
            ]],
            [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => 12.0, 'change_for' => null]],
            ],
            origin: OrderOrigin::Staff,
            bypassBusinessHours: true,
        );

        $item = $order->items()->first();

        // JSON cru — só addon_id/quantity/target, nunca nome/preço. Ordem das
        // chaves não importa (Livewire pode reordenar na (de)serialização).
        $this->assertEquals([['addon_id' => $addon->id, 'quantity' => 2, 'target' => null]], $item->addons);
        $this->assertSame('4.00', $item->addons_total);
        $this->assertSame('12.00', $order->items_total); // 8,00 produto + 4,00 adicional
    }

    public function test_editing_order_beyond_preparing_is_forbidden_without_advanced_permission(): void
    {
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Cliente Teste', 'phone' => '11999990000']);
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Ready,
            'opened_at' => now(),
        ]);

        $this->actingAs($this->userWithRole('Atendente'));

        $this->get(AttendOrder::getUrl(['order' => $order->id]))->assertForbidden();
    }

    public function test_editing_order_beyond_preparing_is_allowed_with_advanced_permission(): void
    {
        $client = Client::create(['tenant_id' => $this->tenant->id, 'name' => 'Cliente Teste', 'phone' => '11999990000']);
        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'items_total' => 40,
            'grand_total' => 40,
            'status' => OrderStatus::Ready,
            'opened_at' => now(),
        ]);

        $this->actingAs($this->userWithRole('Gerente'));

        $this->get(AttendOrder::getUrl(['order' => $order->id]))->assertOk();
    }

    public function test_attendant_creates_order_without_client_and_with_notes(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id)
            ->set('orderNotes', 'Sem gelo, por favor')
            ->call('syncClientData', [
                'phone' => '', 'name' => '', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
                'without_client' => true,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => null, 'change_for' => null]],
            ])
            ->call('save')
            ->assertSet('errorMessage', null);

        $this->assertSame(1, Order::count());

        $order = Order::first();
        $this->assertNull($order->client_id);
        $this->assertSame('Sem gelo, por favor', $order->notes);
    }

    public function test_attendant_splits_payment_across_two_methods(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $cash = PaymentOption::create(['tenant_id' => $this->tenant->id, 'name' => 'Dinheiro', 'is_cash' => true]);

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 100]);

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id)
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [
                    ['payment_option_id' => $cash->id, 'amount' => '60,00', 'change_for' => null],
                    ['payment_option_id' => $this->paymentOption->id, 'amount' => '40,00', 'change_for' => null],
                ],
            ])
            ->call('save')
            ->assertSet('errorMessage', null);

        $order = Order::first();
        $this->assertSame(2, $order->payments()->count());
        $this->assertSame('100.00', (string) $order->payments()->sum('amount'));
        $this->assertSame('60.00', $order->payments()->where('payment_option_name', 'Dinheiro')->value('amount'));
    }

    public function test_save_is_blocked_when_payments_sum_does_not_match_total(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 100]);

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id)
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => '50,00', 'change_for' => null]],
            ])
            ->call('save');

        $this->assertSame(0, Order::count());
    }

    public function test_grand_total_preview_is_passed_to_the_fulfillment_picker(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 55]);

        // A AttendOrder alimenta o FulfillmentPicker (prop `#[Reactive] total`)
        // com o total atual do carrinho; o pré-preenchimento da linha de
        // pagamento em si é coberto por FulfillmentPickerTest.
        $component = Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id);

        $this->assertSame(55.0, $component->instance()->grandTotalPreview);
    }

    public function test_attendant_adds_a_note_to_a_cart_item(): void
    {
        $this->actingAs($this->userWithRole('Atendente'));

        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);

        Livewire::test(AttendOrder::class)
            ->call('addSimpleItem', $product->id)
            ->call('updateItemNote', 0, 'sem cebola')
            ->call('syncClientData', [
                'phone' => '11999990000', 'name' => 'Cliente Teste', 'zip_code' => null,
                'street' => null, 'number' => null, 'complement' => null,
                'neighborhood' => null, 'city' => null, 'state' => null,
            ])
            ->call('syncFulfillmentData', [
                'delivery_option_id' => null,
                'payments' => [['payment_option_id' => $this->paymentOption->id, 'amount' => '40,00', 'change_for' => null]],
            ])
            ->call('save')
            ->assertSet('errorMessage', null);

        $this->assertSame('sem cebola', Order::first()->items()->first()->note);
    }
}

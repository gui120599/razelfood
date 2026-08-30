<?php

namespace Tests\Feature;

use App\Livewire\Menu;
use App\Models\Addon;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fluxo público do cardápio (RN-48) — o "+" de um produto simples com
 * adicionais abre a visualização rápida em vez de adicionar direto; o
 * modal de combo ganha um sub-passo interno de adicionais (nunca um modal
 * novo — ver .ai/rules/menu.md); produto/combo sem nenhum adicional
 * anexado mantém o comportamento de adicionar direto, sem regressão.
 */
class MenuAddonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Pizzaria Teste',
            'slug' => 'pizzaria-teste',
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);

        foreach (range(0, 6) as $weekday) {
            BusinessHour::create([
                'tenant_id' => $this->tenant->id,
                'weekday' => $weekday,
                'opens_at' => '00:00:00',
                'closes_at' => '23:59:59',
                'is_active' => true,
            ]);
        }
    }

    public function test_adding_a_simple_product_with_addons_stores_them_on_the_cart_line(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1, 'show_in_menu' => true]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(Menu::class)
            ->call('addToCart', $product->id, [['addon_id' => $addon->id, 'quantity' => 2, 'target' => null]]);

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertEquals([['addon_id' => $addon->id, 'quantity' => 2, 'target' => null]], $items[0]['addons']);
    }

    public function test_combo_with_addons_requires_the_addons_step_before_adding_to_cart(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        $test = Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo');

        // Ainda não foi pro carrinho — parou no sub-passo de adicionais.
        $this->assertSame('addons', $test->get('comboBuilder')['step']);
        $this->assertCount(0, Cart::items());

        $test->call('setAddonQuantity', $addon->id, 1)
            ->call('setAddonTarget', $addon->id, $flavorA->id)
            ->call('confirmComboAddons');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('combo', $items[0]['type']);
        $this->assertEquals([['addon_id' => $addon->id, 'quantity' => 1, 'target' => $flavorA->id]], $items[0]['addons']);

        // O próprio null do comboBuilder confirma que resetou depois de confirmar.
        $this->assertNull($test->get('comboBuilder')['category_id']);
    }

    public function test_combo_without_any_addon_attached_adds_directly_without_the_addons_step(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);

        Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame([], $items[0]['addons']);
    }

    public function test_addons_step_asks_before_showing_the_addon_list(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        $test = Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo')
            ->assertSet('comboAddonsGate', null)
            ->assertDontSee('Bacon extra');

        $test->call('chooseComboWantsAddons', true)
            ->assertSet('comboAddonsGate', true)
            ->assertSee('Bacon extra');

        $this->assertCount(0, Cart::items());
    }

    public function test_choosing_not_to_add_addons_finalizes_the_combo_immediately(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo')
            ->call('chooseComboWantsAddons', false);

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame([], $items[0]['addons']);
    }

    public function test_skip_combo_addons_clears_partial_selections_and_finalizes_without_addons(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo')
            ->call('chooseComboWantsAddons', true)
            ->call('setAddonQuantity', $addon->id, 2)
            ->call('skipComboAddons');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame([], $items[0]['addons']);
    }

    /**
     * RN-48 refinamento: uma categoria com opção de "sabor único"
     * (flavor_count=1) ainda passa pelo sub-passo de adicionais se o sabor
     * escolhido tiver algum anexado — mas com um único sabor não faz
     * sentido perguntar "produto inteiro ou só esse sabor".
     */
    public function test_target_selector_is_hidden_with_a_single_flavor_combo(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Sabor único', 'flavor_count' => 1, 'display_order' => 1]);
        $flavor = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavor->id, 'addon_id' => $addon->id]);

        Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavor->id)
            ->call('confirmCombo')
            ->call('chooseComboWantsAddons', true)
            ->call('setAddonQuantity', $addon->id, 1)
            ->assertDontSee('Produto inteiro')
            ->assertDontSee('Só Calabresa');
    }

    public function test_target_selector_is_shown_with_multiple_flavors_combo(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorB->id, 'addon_id' => $addon->id]);

        Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo')
            ->call('chooseComboWantsAddons', true)
            ->call('setAddonQuantity', $addon->id, 1)
            ->assertSee('Produto inteiro')
            ->assertSee('Só Marguerita');
    }

    public function test_cart_line_total_includes_addon_cost(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1, 'show_in_menu' => true]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 2]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        $test = Livewire::test(Menu::class)
            ->call('addToCart', $product->id, [['addon_id' => $addon->id, 'quantity' => 3, 'target' => null]]);

        $line = collect($test->get('cartLines'))->first();
        $this->assertSame(14.0, $line['line_total']); // 8,00 produto + 3 x 2,00 adicional
    }
}

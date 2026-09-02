<?php

namespace Tests\Feature\Orders;

use App\Filament\Tenant\Livewire\Orders\AddonPickerModal;
use App\Filament\Tenant\Livewire\Orders\FlavorPickerModal;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
use App\Models\Addon;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cadeia de eventos do painel de atendimento (RN-48): ProductCatalogSelector
 * e FlavorPickerModal precisam desviar pro AddonPickerModal quando o
 * produto/combo tem adicionais anexados — e continuar direto (sem
 * regressão) quando não tem nenhum.
 */
class AddonPickerWiringTest extends TestCase
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
    }

    public function test_selecting_a_simple_product_with_addons_dispatches_addons_requested(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(ProductCatalogSelector::class)
            ->call('selectProduct', $product->id)
            ->assertDispatched('order-addons-requested', type: 'simple', productId: $product->id, flavorIds: [])
            ->assertNotDispatched('order-item-selected');
    }

    public function test_selecting_a_simple_product_without_addons_dispatches_item_selected_directly(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);

        Livewire::test(ProductCatalogSelector::class)
            ->call('selectProduct', $product->id)
            ->assertDispatched('order-item-selected', productId: $product->id)
            ->assertNotDispatched('order-addons-requested');
    }

    public function test_confirming_a_combo_with_addons_dispatches_addons_requested_instead_of_cart_line_confirmed(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo')
            ->assertDispatched('order-addons-requested', type: 'combo', productId: $flavorA->id, flavorIds: [$flavorA->id, $flavorB->id])
            ->assertNotDispatched('order-cart-line-confirmed')
            ->assertSet('open', false);
    }

    public function test_confirming_a_combo_without_addons_dispatches_cart_line_confirmed_directly(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);

        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo')
            ->assertDispatched('order-cart-line-confirmed')
            ->assertNotDispatched('order-addons-requested');
    }

    public function test_addon_picker_confirm_dispatches_cart_line_confirmed_with_selected_addons(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $product->id, [])
            ->call('setQuantity', $addon->id, 2)
            ->call('confirmAddons')
            ->assertDispatched('order-cart-line-confirmed', item: [
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
                'addons' => [['addon_id' => $addon->id, 'quantity' => 2, 'target' => null]],
            ])
            ->assertSet('open', false);
    }

    public function test_addon_picker_confirm_with_no_selection_still_confirms_the_line(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $product->id, [])
            ->call('confirmAddons')
            ->assertDispatched('order-cart-line-confirmed', item: [
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => [],
            ]);
    }

    public function test_addon_picker_asks_before_showing_the_addon_list(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $product->id, [])
            ->assertSet('wantsAddons', null)
            ->assertNotDispatched('order-cart-line-confirmed')
            ->call('chooseWantsAddons', true)
            ->assertSet('wantsAddons', true)
            ->assertNotDispatched('order-cart-line-confirmed');
    }

    public function test_choosing_not_to_add_addons_confirms_the_line_immediately(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $product->id, [])
            ->call('chooseWantsAddons', false)
            ->assertDispatched('order-cart-line-confirmed', item: [
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => [],
            ])
            ->assertSet('open', false);
    }

    /**
     * RN-48 refinamento: com um único sabor selecionado (produto simples, ou
     * combo de flavor_count=1 já achatado em 'simple' por confirmCombo), não
     * faz sentido perguntar "produto inteiro ou só esse sabor" — só existe
     * um sabor possível. O seletor de alvo só deve aparecer com 2+ sabores.
     */
    public function test_target_selector_is_hidden_with_a_single_flavor(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $product->id, [])
            ->call('chooseWantsAddons', true)
            ->call('setQuantity', $addon->id, 1)
            ->assertDontSee('Produto inteiro')
            ->assertDontSee('Só Refrigerante');
    }

    public function test_skip_addons_clears_partial_selections_and_confirms_without_addons(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $product->id, [])
            ->call('chooseWantsAddons', true)
            ->call('setQuantity', $addon->id, 2)
            ->call('skipAddons')
            ->assertDispatched('order-cart-line-confirmed', item: [
                'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null, 'addons' => [],
            ])
            ->assertSet('open', false);
    }

    public function test_edit_for_line_preloads_addons_and_updates_the_line_instead_of_appending(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('editForLine', 2, 'simple', $product->id, [], [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]])
            ->assertSet('wantsAddons', true)
            ->assertSet('editingIndex', 2)
            ->assertSet('selections', [$addon->id => ['quantity' => 1, 'target' => null]])
            ->call('setQuantity', $addon->id, 3)
            ->call('confirmAddons')
            ->assertDispatched('order-line-addons-updated', index: 2, addons: [['addon_id' => $addon->id, 'quantity' => 3, 'target' => null]])
            ->assertNotDispatched('order-cart-line-confirmed')
            ->assertSet('open', false)
            ->assertSet('editingIndex', null);
    }

    public function test_edit_for_line_skip_removes_every_addon_from_the_line(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('editForLine', 0, 'simple', $product->id, [], [['addon_id' => $addon->id, 'quantity' => 2, 'target' => null]])
            ->call('skipAddons')
            ->assertDispatched('order-line-addons-updated', index: 0, addons: [])
            ->assertSet('open', false);
    }

    public function test_target_selector_is_shown_with_multiple_flavors(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorB->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'combo', $flavorA->id, [$flavorA->id, $flavorB->id])
            ->call('chooseWantsAddons', true)
            ->call('setQuantity', $addon->id, 1)
            ->assertSee('Produto inteiro')
            ->assertSee('Só Marguerita');
    }
}

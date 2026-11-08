<?php

namespace Tests\Feature\Orders;

use App\Filament\Tenant\Livewire\Orders\AddonPickerModal;
use App\Filament\Tenant\Livewire\Orders\FlavorPickerModal;
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
 * Visual e fluxo dos modais de pedido (Fase 1.S.1b), copiados do Pizzaria-App: sabores com
 * prévia/lista rica, "Voltar" preservando a seleção e o modal de item simples (quantidade,
 * adicionais, observação e total).
 */
class OrderModalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $pizzas;

    private Product $calabresa;

    private Product $marguerita;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Pizzaria Teste', 'slug' => 'pizzaria-teste', 'whatsapp_number' => '5511999999999']);
        CurrentTenant::set($this->tenant);

        $this->pizzas = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'label' => 'Inteira', 'flavor_count' => 1, 'display_order' => 1]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 2]);

        $this->calabresa = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40,
            'description' => 'Calabresa fatiada com cebola', 'controls_stock' => true, 'stock_quantity' => 6, 'commercial_unit' => 'UN',
        ]);
        $this->marguerita = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Marguerita', 'price' => 50]);
    }

    public function test_flavor_modal_shows_progress_modes_and_rich_flavor_rows(): void
    {
        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id, $this->calabresa->id)
            ->assertSee('1 de 1')
            ->assertSee('Como deseja?')
            ->assertSee('Inteira')
            ->assertSee('Meio a meio')
            ->assertSee('Calabresa fatiada com cebola')
            ->assertSee('R$ 40,00')
            ->assertSee('6 UN')
            ->call('selectFlavorQuantity', $this->pizzas->flavorQuantityOptions()->where('flavor_count', 2)->value('id'))
            ->assertSee('1 de 2')
            ->assertSee('Selecione 1 mais');
    }

    public function test_flavor_modal_previews_the_average_price_as_flavors_are_chosen(): void
    {
        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id)
            ->assertSet('previewPrice', null)
            ->assertDontSee('Prévia:')
            ->call('selectFlavorQuantity', $this->pizzas->flavorQuantityOptions()->where('flavor_count', 2)->value('id'))
            ->call('toggleFlavor', $this->calabresa->id)
            ->assertSeeHtml('<span class="font-bold tabular-nums">R$ 40,00</span>')
            ->call('toggleFlavor', $this->marguerita->id)
            ->assertSeeHtml('<span class="font-bold tabular-nums">R$ 45,00</span>')
            ->assertSee('Meio a meio: valor pela média dos sabores')
            ->assertSee('Selecionados:')
            ->assertSee('Calabresa / Marguerita')
            ->assertSee('1º')
            ->assertSee('2º');
    }

    public function test_flavor_modal_hides_flavors_from_other_tenants_and_the_waiter_menu(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Escondida', 'price' => 30, 'show_in_waiter_menu' => false]);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'whatsapp_number' => '5511888888888']);
        $otherCategory = Category::create(['tenant_id' => $other->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        Product::create(['tenant_id' => $other->id, 'category_id' => $otherCategory->id, 'name' => 'Sabor Alheio', 'price' => 30]);
        CurrentTenant::set($this->tenant);

        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id)
            ->assertSee('Calabresa')
            ->assertDontSee('Escondida')
            ->assertDontSee('Sabor Alheio');
    }

    public function test_flavor_note_travels_with_the_confirmed_line(): void
    {
        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id, $this->calabresa->id)
            ->call('selectFlavorQuantity', $this->pizzas->flavorQuantityOptions()->where('flavor_count', 2)->value('id'))
            ->call('toggleFlavor', $this->marguerita->id)
            ->set('note', '  bem assada  ')
            ->call('confirmCombo')
            ->assertDispatched('order-cart-line-confirmed', item: [
                'type' => 'combo', 'product_id' => $this->calabresa->id, 'flavor_ids' => [$this->calabresa->id, $this->marguerita->id],
                'quantity' => 1, 'note' => 'bem assada', 'addons' => [], 'gifts' => [],
            ]);
    }

    public function test_back_from_the_addon_step_reopens_the_flavor_modal_with_the_selection(): void
    {
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->calabresa->id, 'addon_id' => $addon->id]);

        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id, $this->calabresa->id)
            ->call('selectFlavorQuantity', $this->pizzas->flavorQuantityOptions()->where('flavor_count', 2)->value('id'))
            ->call('toggleFlavor', $this->marguerita->id)
            ->set('note', 'sem cebola')
            ->assertSee('Continuar')
            ->call('confirmCombo')
            ->assertDispatched('order-addons-requested', type: 'combo', productId: $this->calabresa->id, flavorIds: [$this->calabresa->id, $this->marguerita->id], note: 'sem cebola', canGoBack: true)
            ->assertSet('open', false)
            ->assertSet('comboBuilder.flavor_ids', [$this->calabresa->id, $this->marguerita->id])
            ->call('resume')
            ->assertSet('open', true)
            ->assertSet('comboBuilder.flavor_ids', [$this->calabresa->id, $this->marguerita->id])
            ->assertSet('note', 'sem cebola');
    }

    public function test_flavor_selection_is_discarded_when_the_addon_step_finishes_or_is_cancelled(): void
    {
        foreach (['order-cart-line-confirmed', 'order-addons-cancelled'] as $event) {
            Livewire::test(FlavorPickerModal::class)
                ->call('startCombo', $this->pizzas->id, $this->calabresa->id)
                ->set('open', false)
                ->dispatch($event)
                ->assertSet('comboBuilder.flavor_ids', [])
                ->call('resume')
                ->assertSet('open', false);
        }
    }

    public function test_addon_step_for_a_combo_shows_the_flavor_names_and_the_back_button(): void
    {
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->calabresa->id, 'addon_id' => $addon->id]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->marguerita->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'combo', $this->calabresa->id, [$this->calabresa->id, $this->marguerita->id], 'sem cebola', true)
            ->assertSee('Quer adicionar algo?')
            ->assertSee('Calabresa / Marguerita')
            ->assertSee('Voltar')
            ->assertSee('Sem adicionais')
            ->assertSeeHtml('<span class="font-bold tabular-nums">R$ 45,00</span>')
            ->assertDontSee('Quantidade')
            ->call('setQuantity', $addon->id, 1)
            ->assertSeeHtml('<span class="font-bold tabular-nums">R$ 51,00</span>')
            ->call('goBack')
            ->assertDispatched('order-combo-resume')
            ->assertSet('open', false);
    }

    public function test_cancelling_the_addon_step_of_a_combo_tells_the_flavor_modal_to_discard(): void
    {
        Livewire::test(AddonPickerModal::class)
            ->call('open', 'combo', $this->calabresa->id, [$this->calabresa->id, $this->marguerita->id], null, true)
            ->call('cancel')
            ->assertDispatched('order-addons-cancelled')
            ->assertSet('open', false);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $this->calabresa->id, [])
            ->call('cancel')
            ->assertNotDispatched('order-addons-cancelled');
    }

    public function test_simple_item_modal_shows_header_stock_quantity_note_and_item_total(): void
    {
        $drink = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 2])->id,
            'name' => 'Refrigerante', 'price' => 8, 'controls_stock' => true, 'stock_quantity' => 12, 'commercial_unit' => 'UN',
        ]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 1.5]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $drink->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $drink->id, [])
            ->assertSee('Refrigerante')
            ->assertSee('Bebidas')
            ->assertSee('Estoque: 12 UN')
            ->assertSee('Quantidade')
            ->assertSee('Observação')
            ->assertSee('Total do item')
            ->assertSee('R$ 8,00')
            ->call('incrementQuantity')
            ->call('incrementQuantity')
            ->assertSee('R$ 24,00')
            ->call('setQuantity', $addon->id, 1)
            ->assertSee('R$ 28,50')
            ->set('note', 'bem gelado')
            ->call('confirmAddons')
            ->assertDispatched('order-cart-line-confirmed', item: [
                'type' => 'simple', 'product_id' => $drink->id, 'flavor_ids' => [], 'quantity' => 3, 'note' => 'bem gelado',
                'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]], 'gifts' => [],
            ]);
    }

    public function test_item_quantity_stays_between_one_and_ninety_nine(): void
    {
        $component = Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $this->marguerita->id, [])
            ->call('decrementQuantity')
            ->assertSet('quantity', 1);

        $component->set('quantity', 99)->call('incrementQuantity')->assertSet('quantity', 99);
    }

    public function test_editing_the_addons_of_a_cart_line_hides_quantity_and_note(): void
    {
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->marguerita->id, 'addon_id' => $addon->id]);

        Livewire::test(AddonPickerModal::class)
            ->call('editForLine', 0, 'simple', $this->marguerita->id, [], [])
            ->assertSee('Adicionais')
            ->assertSee('Bacon extra')
            ->assertSee('Salvar adicionais')
            ->assertDontSee('Quantidade')
            ->assertDontSee('Observação');
    }

    public function test_confirming_twice_adds_the_line_only_once_and_does_not_error(): void
    {
        $component = Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $this->marguerita->id, [])
            ->call('confirmAddons')
            ->assertSet('open', false);

        $component->call('confirmAddons')
            ->assertOk()
            ->assertNotDispatched('order-cart-line-confirmed');
    }

    public function test_editing_a_line_whose_product_no_longer_exists_still_renders(): void
    {
        $gone = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Removida', 'price' => 10]);
        $goneId = $gone->id;
        $gone->forceDelete();

        Livewire::test(AddonPickerModal::class)
            ->call('editForLine', 0, 'simple', $goneId, [], [])
            ->assertOk()
            ->assertSet('open', true);
    }

    public function test_addon_row_shows_the_price_of_the_product_link_and_respects_its_max_quantity(): void
    {
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->marguerita->id, 'addon_id' => $addon->id, 'price' => 4, 'max_quantity' => 2]);

        Livewire::test(AddonPickerModal::class)
            ->call('open', 'simple', $this->marguerita->id, [])
            ->assertSee('+ R$ 4,00')
            ->assertDontSee('+ R$ 6,00')
            ->call('setQuantity', $addon->id, 5)
            ->assertSet("selections.{$addon->id}.quantity", 2)
            ->assertSee('R$ 58,00');
    }

    public function test_continue_button_is_only_offered_when_the_chosen_flavors_have_something_to_ask(): void
    {
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->calabresa->id, 'addon_id' => $addon->id]);

        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id, $this->marguerita->id)
            ->assertSet('hasExtras', false)
            ->assertSee('Confirmar');

        $addon->delete();

        Livewire::test(FlavorPickerModal::class)
            ->call('startCombo', $this->pizzas->id, $this->calabresa->id)
            ->assertSet('hasExtras', false);
    }
}

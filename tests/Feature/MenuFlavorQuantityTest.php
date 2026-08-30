<?php

namespace Tests\Feature;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Actions\Menu\ResolvePriceForProduct;
use App\Livewire\Menu;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\FlashPromotion;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class MenuFlavorQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    private Product $flavorA;

    private Product $flavorB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Pizzaria Teste',
            'slug' => 'pizzaria-teste',
            'whatsapp_number' => '5511999999999',
        ]);

        $this->tenant = $tenant;

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);

        // Aberto 24h todo dia, pra não depender do horário em que os testes rodam.
        foreach (range(0, 6) as $weekday) {
            BusinessHour::create([
                'tenant_id' => $tenant->id,
                'weekday' => $weekday,
                'opens_at' => '00:00:00',
                'closes_at' => '23:59:59',
                'is_active' => true,
            ]);
        }

        $this->category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ]);

        FlavorQuantityOption::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'label' => 'Sabor único',
            'flavor_count' => 1,
            'display_order' => 1,
        ]);

        FlavorQuantityOption::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'label' => 'Meio a meio',
            'flavor_count' => 2,
            'display_order' => 2,
        ]);

        $this->flavorA = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);

        $this->flavorB = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Mussarela',
            'price' => 44,
        ]);
    }

    public function test_selecting_sabor_unico_adds_a_single_product_to_the_cart(): void
    {
        $optionId = FlavorQuantityOption::where('flavor_count', 1)->first()->id;

        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id)
            ->call('selectFlavorQuantity', $optionId)
            ->assertSet('comboBuilder.required_count', 1)
            ->call('toggleFlavor', $this->flavorA->id)
            ->call('confirmCombo')
            ->assertSet('showCart', true);

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('simple', $items[0]['type']);
        $this->assertSame($this->flavorA->id, $items[0]['product_id']);
    }

    public function test_selecting_meio_a_meio_adds_a_combo_with_both_flavors(): void
    {
        $optionId = FlavorQuantityOption::where('flavor_count', 2)->first()->id;

        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id)
            ->call('selectFlavorQuantity', $optionId)
            ->call('toggleFlavor', $this->flavorA->id)
            ->call('toggleFlavor', $this->flavorB->id)
            ->call('confirmCombo');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('combo', $items[0]['type']);
        $this->assertSame([$this->flavorA->id, $this->flavorB->id], $items[0]['flavor_ids']);
    }

    /**
     * Modal único (sem etapas): abrir a partir do "+" de um produto
     * específico já pré-seleciona esse produto, na quantidade padrão.
     */
    public function test_start_combo_preselects_the_clicked_product_with_the_default_quantity(): void
    {
        $optionId1 = FlavorQuantityOption::where('flavor_count', 1)->first()->id;

        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id, $this->flavorA->id)
            ->assertSet('comboBuilder.quantity_option_id', $optionId1)
            ->assertSet('comboBuilder.required_count', 1)
            ->assertSet('comboBuilder.flavor_ids', [$this->flavorA->id]);
    }

    public function test_switching_to_a_bigger_quantity_keeps_the_preselected_flavor_to_complement_it(): void
    {
        $optionId2 = FlavorQuantityOption::where('flavor_count', 2)->first()->id;

        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id, $this->flavorA->id)
            ->call('selectFlavorQuantity', $optionId2)
            ->assertSet('comboBuilder.required_count', 2)
            ->assertSet('comboBuilder.flavor_ids', [$this->flavorA->id])
            ->call('toggleFlavor', $this->flavorB->id)
            ->call('confirmCombo');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('combo', $items[0]['type']);
        $this->assertSame([$this->flavorA->id, $this->flavorB->id], $items[0]['flavor_ids']);
    }

    public function test_switching_to_a_smaller_quantity_truncates_the_selection_instead_of_wiping_it(): void
    {
        $optionId1 = FlavorQuantityOption::where('flavor_count', 1)->first()->id;
        $optionId2 = FlavorQuantityOption::where('flavor_count', 2)->first()->id;

        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id, $this->flavorA->id)
            ->call('selectFlavorQuantity', $optionId2)
            ->call('toggleFlavor', $this->flavorB->id)
            ->assertSet('comboBuilder.flavor_ids', [$this->flavorA->id, $this->flavorB->id])
            ->call('selectFlavorQuantity', $optionId1)
            ->assertSet('comboBuilder.required_count', 1)
            ->assertSet('comboBuilder.flavor_ids', [$this->flavorA->id]);
    }

    public function test_clicking_a_different_flavor_in_single_mode_swaps_the_preselected_one(): void
    {
        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id, $this->flavorA->id)
            ->call('toggleFlavor', $this->flavorB->id)
            ->assertSet('comboBuilder.flavor_ids', [$this->flavorB->id]);
    }

    public function test_confirming_combo_with_fewer_flavors_than_required_does_nothing(): void
    {
        $optionId = FlavorQuantityOption::where('flavor_count', 2)->first()->id;

        Livewire::test(Menu::class)
            ->call('startCombo', $this->category->id)
            ->call('selectFlavorQuantity', $optionId)
            ->call('toggleFlavor', $this->flavorA->id)
            ->call('confirmCombo');

        $this->assertCount(0, Cart::items());
    }

    public function test_product_card_offers_direct_add_when_category_has_no_flavor_options_configured(): void
    {
        $bareCategory = Category::create([
            'tenant_id' => $this->category->tenant_id,
            'name' => 'Bebidas',
            'display_order' => 2,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ]);

        $soda = Product::create([
            'tenant_id' => $this->category->tenant_id,
            'category_id' => $bareCategory->id,
            'name' => 'Refrigerante',
            'price' => 8,
        ]);

        Livewire::test(Menu::class)
            ->call('addToCart', $soda->id)
            ->assertSet('showCart', true);

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('simple', $items[0]['type']);
        $this->assertSame($soda->id, $items[0]['product_id']);
    }

    public function test_product_in_whole_unit_only_flash_promotion_is_flagged_as_flavor_combo_blocked(): void
    {
        $this->attachWholeUnitOnlyPromotion($this->flavorA);

        $resolved = app(ResolvePriceForProduct::class)($this->flavorA->fresh());

        $this->assertNotNull($resolved->matchedFlashPromotion);
        $this->assertFalse($resolved->matchedFlashPromotion->allows_flavors);
    }

    public function test_flash_promotion_product_card_falls_back_to_direct_add_when_promo_blocks_flavors(): void
    {
        $this->attachWholeUnitOnlyPromotion($this->flavorA);

        // O produto está numa promoção "só inteira": clicar nele deve
        // adicionar direto, sem abrir o seletor de sabores — mesmo a
        // categoria permitindo sabores normalmente.
        Livewire::test(Menu::class)
            ->call('addToCart', $this->flavorA->id)
            ->assertSet('showCart', true);

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('simple', $items[0]['type']);
    }

    public function test_resolving_a_combo_with_a_whole_unit_only_promo_product_throws(): void
    {
        $this->attachWholeUnitOnlyPromotion($this->flavorA);

        $this->expectException(InvalidArgumentException::class);

        app(ResolvePriceForCartLine::class)([
            'type' => 'combo',
            'product_id' => $this->flavorA->id,
            'flavor_ids' => [$this->flavorA->id, $this->flavorB->id],
            'quantity' => 1,
            'note' => null,
        ]);
    }

    public function test_resolving_a_combo_without_any_blocked_promotion_still_works(): void
    {
        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'combo',
            'product_id' => $this->flavorA->id,
            'flavor_ids' => [$this->flavorA->id, $this->flavorB->id],
            'quantity' => 1,
            'note' => null,
        ]);

        $this->assertSame(round((40 + 44) / 2, 2), $resolved['unit_price']);
    }

    /**
     * Miniatura na navegação por categoria: usa a primeira imagem de
     * produto disponível (direto ou, na falta, de subcategoria); sem
     * nenhuma imagem em toda a árvore, fica null (a view cai no
     * placeholder neutro).
     */
    public function test_category_nav_thumbnail_uses_the_first_product_image_found(): void
    {
        $this->flavorB->update(['image_path' => 'products/mussarela.jpg']);

        $category = Livewire::test(Menu::class)
            ->instance()
            ->categories
            ->firstWhere('id', $this->category->id);

        $this->assertSame($this->flavorB->fresh()->image_url, $category->nav_thumbnail_url);
    }

    public function test_category_nav_thumbnail_falls_back_to_a_child_categorys_product_image(): void
    {
        Product::whereIn('id', [$this->flavorA->id, $this->flavorB->id])->update(['image_path' => null]);

        $child = Category::create([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $this->category->id,
            'name' => 'Pizzas Doces',
            'display_order' => 1,
            'show_in_menu' => true,
        ]);

        $childProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $child->id,
            'name' => 'Chocolate',
            'price' => 45,
            'image_path' => 'products/chocolate.jpg',
        ]);

        $category = Livewire::test(Menu::class)
            ->instance()
            ->categories
            ->firstWhere('id', $this->category->id);

        $this->assertSame($childProduct->fresh()->image_url, $category->nav_thumbnail_url);
    }

    public function test_category_nav_thumbnail_is_null_when_no_product_has_an_image(): void
    {
        $category = Livewire::test(Menu::class)
            ->instance()
            ->categories
            ->firstWhere('id', $this->category->id);

        $this->assertNull($category->nav_thumbnail_url);
    }

    private function attachWholeUnitOnlyPromotion(Product $product): FlashPromotion
    {
        $promotion = FlashPromotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo só inteira',
            'is_active' => true,
            'allows_flavors' => false,
        ]);

        $promotion->products()->attach($product->id, [
            'tenant_id' => $this->tenant->id,
            'promotional_price' => 30,
        ]);

        return $promotion;
    }
}

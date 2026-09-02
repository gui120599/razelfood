<?php

namespace Tests\Feature;

use App\Livewire\Menu;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\ProductGift;
use App\Models\Tenant;
use App\Support\Cart;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fluxo público do cardápio para brindes (RN-53): o "+" de um produto simples
 * com brinde ativo abre a visualização rápida (não adiciona direto); o cliente
 * marca ou não o checkbox; o combo ganha um sub-passo de brinde antes dos
 * adicionais.
 */
class MenuGiftTest extends TestCase
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

    public function test_simple_product_with_gift_is_added_with_the_accepted_gift(): void
    {
        [$product, $gift] = $this->makeSimpleProductWithGift();

        Livewire::test(Menu::class)
            ->call('viewProduct', $product->id)
            ->call('toggleGift', $gift->id)
            ->call('addFromView');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertEquals([['gift_product_id' => $gift->id, 'accepted' => true]], $items[0]['gifts']);
    }

    public function test_gift_can_be_declined_and_the_line_carries_no_gift(): void
    {
        [$product] = $this->makeSimpleProductWithGift();

        Livewire::test(Menu::class)
            ->call('viewProduct', $product->id)
            ->call('addFromView');

        $this->assertSame([], Cart::items()[0]['gifts']);
    }

    public function test_attach_price_flags_a_product_that_has_an_active_gift(): void
    {
        [$product] = $this->makeSimpleProductWithGift();

        $viewing = Livewire::test(Menu::class)
            ->call('viewProduct', $product->id)
            ->get('viewingProduct');

        $this->assertTrue($viewing->resolved_has_gifts);
        $this->assertContains('Guaraná 1,5L', $viewing->resolved_gift_names);
    }

    public function test_combo_with_gift_stops_at_the_gifts_step_before_adding(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'gift_product_id' => $soda->id, 'quantity' => 1, 'is_active' => true]);

        $test = Livewire::test(Menu::class)
            ->call('startCombo', $category->id, $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo');

        $this->assertSame('gifts', $test->get('comboBuilder')['step']);
        $this->assertCount(0, Cart::items());

        $test->call('toggleGift', $soda->id)
            ->call('confirmComboGifts');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('combo', $items[0]['type']);
        $this->assertEquals([['gift_product_id' => $soda->id, 'accepted' => true]], $items[0]['gifts']);
    }

    public function test_cart_line_shows_the_gift_display(): void
    {
        [$product, $gift] = $this->makeSimpleProductWithGift();

        $test = Livewire::test(Menu::class)
            ->call('viewProduct', $product->id)
            ->call('toggleGift', $gift->id)
            ->call('addFromView');

        $line = $test->get('cartLines')[0];
        $this->assertNotEmpty($line['gifts_display']);
        $this->assertStringContainsString('Guaraná 1,5L', $line['gifts_display'][0]);
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function makeSimpleProductWithGift(): array
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'show_in_menu' => true]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Pizza Calabresa', 'price' => 65]);
        $gift = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'gift_product_id' => $gift->id, 'quantity' => 1, 'is_active' => true]);

        return [$product, $gift];
    }
}

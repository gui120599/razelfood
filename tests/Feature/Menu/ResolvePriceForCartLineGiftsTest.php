<?php

namespace Tests\Feature\Menu;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\ProductGift;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resolução server-side dos brindes (RN-53): a lista de brindes sai SEMPRE
 * dos vínculos product_gift ativos do produto-âncora — a seleção do cliente
 * só decide aceite. Brinde forjado/inativo/de quantidade de sabores não
 * habilitada é ignorado. O brinde nunca tem preço.
 */
class ResolvePriceForCartLineGiftsTest extends TestCase
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

    public function test_accepted_gift_resolves_with_pivot_quantity_and_zero_price_impact(): void
    {
        [$product, $gift] = $this->makeProductWithGift(quantity: 2);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $gift->id, 'accepted' => true]],
        ]);

        $this->assertSame([
            ['gift_product_id' => $gift->id, 'quantity' => 2, 'accepted' => true, 'award_mode' => 'per_quantity'],
        ], $resolved['gifts']);
        $this->assertSame(45.0, $resolved['unit_price']);
        $this->assertSame(0.0, $resolved['addons_total']);
    }

    public function test_declined_gift_is_still_returned_with_accepted_false(): void
    {
        [$product, $gift] = $this->makeProductWithGift();

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [],
        ]);

        $this->assertSame([
            ['gift_product_id' => $gift->id, 'quantity' => 1, 'accepted' => false, 'award_mode' => 'per_quantity'],
        ], $resolved['gifts']);
    }

    public function test_forged_gift_product_id_not_linked_is_ignored(): void
    {
        [$product] = $this->makeProductWithGift();
        $unrelated = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $product->category_id, 'name' => 'Cerveja', 'price' => 12]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $unrelated->id, 'accepted' => true]],
        ]);

        $this->assertCount(1, $resolved['gifts']);
        $this->assertNotContains($unrelated->id, array_column($resolved['gifts'], 'gift_product_id'));
    }

    public function test_inactive_gift_link_is_ignored(): void
    {
        [$product, $gift] = $this->makeProductWithGift();
        ProductGift::where('product_id', $product->id)->update(['is_active' => false]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $gift->id, 'accepted' => true]],
        ]);

        $this->assertSame([], $resolved['gifts']);
    }

    public function test_soft_deleted_gift_product_is_not_offered(): void
    {
        [$product, $gift] = $this->makeProductWithGift();
        $gift->delete();

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $gift->id, 'accepted' => true]],
        ]);

        $this->assertSame([], $resolved['gifts']);
    }

    public function test_flavor_counts_restricts_gift_to_configured_quantities(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Sabor único', 'flavor_count' => 1, 'display_order' => 1]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 2]);

        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        // Brinde só habilitado para "item simples" (1 sabor).
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'gift_product_id' => $soda->id, 'quantity' => 1, 'is_active' => true, 'flavor_counts' => [1]]);

        $simple = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $flavorA->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $soda->id, 'accepted' => true]],
        ]);
        $this->assertCount(1, $simple['gifts']);

        $combo = app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $soda->id, 'accepted' => true]],
        ]);
        $this->assertSame([], $combo['gifts']);
    }

    public function test_same_gift_from_two_flavors_is_deduplicated_with_the_higher_quantity(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);

        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'gift_product_id' => $soda->id, 'quantity' => 1, 'is_active' => true]);
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorB->id, 'gift_product_id' => $soda->id, 'quantity' => 2, 'is_active' => true]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $soda->id, 'accepted' => true]],
        ]);

        $this->assertSame([
            ['gift_product_id' => $soda->id, 'quantity' => 2, 'accepted' => true, 'award_mode' => 'per_quantity'],
        ], $resolved['gifts']);
    }

    public function test_award_mode_conflict_between_two_flavors_resolves_to_per_quantity(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio', 'flavor_count' => 2, 'display_order' => 1]);

        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'gift_product_id' => $soda->id, 'quantity' => 1, 'is_active' => true, 'award_mode' => 'per_order']);
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorB->id, 'gift_product_id' => $soda->id, 'quantity' => 1, 'is_active' => true, 'award_mode' => 'per_quantity']);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $soda->id, 'accepted' => true]],
        ]);

        $this->assertSame('per_quantity', $resolved['gifts'][0]['award_mode']);
    }

    public function test_per_order_link_resolves_with_award_mode_per_order(): void
    {
        [$product, $gift] = $this->makeProductWithGift();
        ProductGift::where('product_id', $product->id)->update(['award_mode' => 'per_order']);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'gifts' => [['gift_product_id' => $gift->id, 'accepted' => true]],
        ]);

        $this->assertSame('per_order', $resolved['gifts'][0]['award_mode']);
    }

    /**
     * @return array{0: Product, 1: Product}
     */
    private function makeProductWithGift(int $quantity = 1): array
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Pizza Calabresa', 'price' => 45]);
        $gift = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        ProductGift::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'gift_product_id' => $gift->id,
            'quantity' => $quantity,
            'is_active' => true,
        ]);

        return [$product, $gift];
    }
}

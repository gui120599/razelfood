<?php

namespace Tests\Feature\Menu;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Models\Addon;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Preço/estoque de adicionais (RN-48): custo = preço_efetivo × quantidade ×
 * fração do alvo. Fração = 100% pro produto/combo inteiro; fração =
 * flavor_shares do sabor escolhido pra alvo específico — reaproveita o
 * mesmo rateio já usado pra estoque de sabores, nunca um cálculo paralelo.
 */
class ResolvePriceForCartLineAddonsTest extends TestCase
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

    public function test_simple_product_addon_costs_full_price_times_quantity(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Frango com Bacon', 'price' => 45]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]],
        ]);

        $this->assertSame(6.0, $resolved['addons_total']);
    }

    public function test_combo_addon_targeted_at_one_flavor_costs_price_times_flavor_share(): void
    {
        [$category, $flavorA, $flavorB] = $this->makeComboCategory(50, 50);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => $flavorA->id]],
        ]);

        // Exemplo literal do dono: R$6 x 0,5 (meio a meio 50/50) = R$3.
        $this->assertSame(3.0, $resolved['addons_total']);
    }

    public function test_combo_addon_applied_to_whole_product_costs_full_price_when_attached_to_all_flavors(): void
    {
        [$category, $flavorA, $flavorB] = $this->makeComboCategory(50, 50);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Catupiry extra', 'price' => 8]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorB->id, 'addon_id' => $addon->id]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]],
        ]);

        $this->assertSame(8.0, $resolved['addons_total']);
    }

    public function test_combo_addon_applied_to_whole_product_requires_attachment_to_every_flavor(): void
    {
        [$category, $flavorA, $flavorB] = $this->makeComboCategory(50, 50);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Catupiry extra', 'price' => 8]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);
        // Não anexado a $flavorB.

        $this->expectException(InvalidArgumentException::class);

        app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]],
        ]);
    }

    public function test_addon_target_outside_flavor_ids_is_rejected(): void
    {
        [$category, $flavorA, $flavorB] = $this->makeComboCategory(50, 50);
        $outsider = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $flavorA->id, 'addon_id' => $addon->id]);

        $this->expectException(InvalidArgumentException::class);

        app(ResolvePriceForCartLine::class)([
            'type' => 'combo', 'product_id' => $flavorA->id, 'flavor_ids' => [$flavorA->id, $flavorB->id], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => $outsider->id]],
        ]);
    }

    public function test_addon_not_attached_to_product_is_rejected(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        // Sem ProductAddon::create(...) — não anexado.

        $this->expectException(InvalidArgumentException::class);

        app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]],
        ]);
    }

    public function test_pivot_price_override_takes_precedence_over_base_price(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 2]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id, 'price' => 5]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 1, 'target' => null]],
        ]);

        $this->assertSame(5.0, $resolved['addons_total']);
    }

    public function test_addon_quantity_beyond_pivot_max_quantity_is_rejected(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 1]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Refrigerante', 'price' => 8]);
        $addon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Gelo extra', 'price' => 2]);
        ProductAddon::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'addon_id' => $addon->id, 'max_quantity' => 2]);

        $this->expectException(InvalidArgumentException::class);

        app(ResolvePriceForCartLine::class)([
            'type' => 'simple', 'product_id' => $product->id, 'flavor_ids' => [], 'quantity' => 1, 'note' => null,
            'addons' => [['addon_id' => $addon->id, 'quantity' => 3, 'target' => null]],
        ]);
    }

    /**
     * @return array{0: Category, 1: Product, 2: Product}
     */
    private function makeComboCategory(float $shareA, float $shareB): array
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1, 'allows_flavors' => true]);
        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'label' => 'Meio a meio',
            'flavor_count' => 2, 'display_order' => 1, 'flavor_shares' => [$shareA, $shareB],
        ]);
        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $category->id, 'name' => 'Marguerita', 'price' => 50]);

        return [$category, $flavorA, $flavorB];
    }
}

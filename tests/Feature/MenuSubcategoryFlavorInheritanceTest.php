<?php

namespace Tests\Feature;

use App\Actions\Menu\ResolvePriceForCartLine;
use App\Livewire\Menu;
use App\Models\BusinessHour;
use App\Models\Category;
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

/**
 * Combo de sabores numa SUBCATEGORIA: pode herdar as quantidades da
 * categoria pai (`inherit_flavor_options`) ou cadastrar as próprias.
 */
class MenuSubcategoryFlavorInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $parent;

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

        $this->parent = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ]);
    }

    private function parentOption(int $count, string $label): void
    {
        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->parent->id,
            'label' => $label,
            'flavor_count' => $count,
            'display_order' => $count,
        ]);
    }

    /**
     * @return array{0: Category, 1: Product, 2: Product}
     */
    private function subcategoryWithFlavors(array $attributes): array
    {
        $sub = Category::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $this->parent->id,
            'name' => 'Pizzas Salgadas',
            'display_order' => 1,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ], $attributes));

        $flavorA = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $sub->id, 'name' => 'Calabresa', 'price' => 40]);
        $flavorB = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $sub->id, 'name' => 'Mussarela', 'price' => 44]);

        return [$sub, $flavorA, $flavorB];
    }

    public function test_subcategory_inheriting_uses_the_parent_flavor_quantities(): void
    {
        $this->parentOption(1, 'Sabor único');
        $this->parentOption(2, 'Meio a meio');

        [$sub, $flavorA, $flavorB] = $this->subcategoryWithFlavors(['inherit_flavor_options' => true]);
        $option = FlavorQuantityOption::where('flavor_count', 2)->first();

        Livewire::test(Menu::class)
            ->assertSee('Calabresa')
            ->call('startCombo', $sub->id)
            ->assertSet('comboBuilder.required_count', 1)
            ->call('selectFlavorQuantity', $option->id)
            ->assertSet('comboBuilder.required_count', 2)
            ->call('toggleFlavor', $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo');

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('combo', $items[0]['type']);
        $this->assertEqualsCanonicalizing([$flavorA->id, $flavorB->id], $items[0]['flavor_ids']);
    }

    public function test_subcategory_with_its_own_flavor_quantities(): void
    {
        $this->parentOption(2, 'Meio a meio');

        [$sub, $flavorA, $flavorB] = $this->subcategoryWithFlavors(['inherit_flavor_options' => false]);
        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $sub->id,
            'label' => 'Dois sabores',
            'flavor_count' => 2,
            'display_order' => 1,
        ]);
        $ownOption = FlavorQuantityOption::where('category_id', $sub->id)->first();

        Livewire::test(Menu::class)
            ->call('startCombo', $sub->id)
            ->call('selectFlavorQuantity', $ownOption->id)
            ->call('toggleFlavor', $flavorA->id)
            ->call('toggleFlavor', $flavorB->id)
            ->call('confirmCombo');

        $this->assertSame('combo', Cart::items()[0]['type']);
    }

    public function test_subcategory_allowing_flavors_without_resolvable_options_has_no_working_combo(): void
    {
        // pai sem opções, subcategoria não herda e não cadastrou as próprias
        [$sub, $flavorA] = $this->subcategoryWithFlavors(['inherit_flavor_options' => false]);

        Livewire::test(Menu::class)
            ->call('startCombo', $sub->id)
            ->assertSet('comboBuilder.required_count', null)
            ->call('addToCart', $flavorA->id);

        $items = Cart::items();
        $this->assertCount(1, $items);
        $this->assertSame('simple', $items[0]['type']);
    }

    public function test_resolve_price_for_a_subcategory_combo_uses_the_inherited_option(): void
    {
        $this->parentOption(2, 'Meio a meio');
        [$sub, $flavorA, $flavorB] = $this->subcategoryWithFlavors(['inherit_flavor_options' => true]);

        $resolved = app(ResolvePriceForCartLine::class)([
            'type' => 'combo',
            'product_id' => $flavorA->id,
            'flavor_ids' => [$flavorA->id, $flavorB->id],
            'quantity' => 1,
            'note' => null,
        ]);

        $this->assertSame(round((40 + 44) / 2, 2), $resolved['unit_price']);
    }

    public function test_resolve_price_throws_when_no_option_is_resolvable(): void
    {
        [$sub, $flavorA, $flavorB] = $this->subcategoryWithFlavors(['inherit_flavor_options' => false]);

        $this->expectException(InvalidArgumentException::class);

        app(ResolvePriceForCartLine::class)([
            'type' => 'combo',
            'product_id' => $flavorA->id,
            'flavor_ids' => [$flavorA->id, $flavorB->id],
            'quantity' => 1,
            'note' => null,
        ]);
    }
}

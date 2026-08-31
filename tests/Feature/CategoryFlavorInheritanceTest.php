<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Category::resolvedFlavorQuantityOptions()` é a fonte única das opções de
 * quantidade de sabores no cardápio/checkout/PDV.
 */
class CategoryFlavorInheritanceTest extends TestCase
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

        $this->parent = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ]);

        foreach ([['Sabor único', 1], ['Meio a meio', 2]] as [$label, $count]) {
            FlavorQuantityOption::create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $this->parent->id,
                'label' => $label,
                'flavor_count' => $count,
                'display_order' => $count,
            ]);
        }
    }

    private function subcategory(array $attributes): Category
    {
        return Category::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $this->parent->id,
            'name' => 'Pizzas Salgadas',
            'display_order' => 1,
            'show_in_menu' => true,
            'allows_flavors' => true,
        ], $attributes));
    }

    public function test_root_category_resolves_its_own_options(): void
    {
        $this->assertCount(2, $this->parent->resolvedFlavorQuantityOptions());
        $this->assertFalse($this->parent->inheritsFlavorOptions());
    }

    public function test_subcategory_not_inheriting_resolves_its_own_options(): void
    {
        $sub = $this->subcategory(['inherit_flavor_options' => false]);

        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $sub->id,
            'label' => 'Três sabores',
            'flavor_count' => 3,
            'display_order' => 1,
        ]);

        $resolved = $sub->fresh()->load('parent.flavorQuantityOptions', 'flavorQuantityOptions')->resolvedFlavorQuantityOptions();

        $this->assertCount(1, $resolved);
        $this->assertSame(3, $resolved->first()->flavor_count);
    }

    public function test_subcategory_inheriting_resolves_the_parent_options(): void
    {
        $sub = $this->subcategory(['inherit_flavor_options' => true]);

        $resolved = $sub->fresh()->load('parent.flavorQuantityOptions', 'flavorQuantityOptions')->resolvedFlavorQuantityOptions();

        $this->assertTrue($sub->inheritsFlavorOptions());
        $this->assertEqualsCanonicalizing([1, 2], $resolved->pluck('flavor_count')->all());
    }

    public function test_inheriting_but_parent_has_no_options_resolves_empty(): void
    {
        $this->parent->flavorQuantityOptions()->delete();

        $sub = $this->subcategory(['inherit_flavor_options' => true]);

        $resolved = $sub->fresh()->load('parent.flavorQuantityOptions', 'flavorQuantityOptions')->resolvedFlavorQuantityOptions();

        $this->assertTrue($resolved->isEmpty());
    }
}

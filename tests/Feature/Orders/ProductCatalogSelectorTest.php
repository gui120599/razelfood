<?php

namespace Tests\Feature\Orders;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Catálogo de produtos da tela de criar pedido (AttendOrder). Cobre a
 * navegação por categoria de dois níveis (raiz + subcategorias) e os
 * metadados de apresentação (miniatura da categoria, categoria do produto).
 */
class ProductCatalogSelectorTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $root;

    private Category $salgadas;

    private Category $doces;

    private Product $calabresa;

    private Product $brigadeiro;

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

        // "Pizzas" não tem produto direto — tudo mora nas subcategorias.
        $this->root = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $this->salgadas = Category::create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->root->id, 'name' => 'Salgadas', 'display_order' => 1]);
        $this->doces = Category::create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->root->id, 'name' => 'Doces', 'display_order' => 2]);

        $this->calabresa = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $this->salgadas->id,
            'name' => 'Calabresa', 'price' => 40, 'image_path' => 'produtos/calabresa.jpg',
        ]);
        $this->brigadeiro = Product::create([
            'tenant_id' => $this->tenant->id, 'category_id' => $this->doces->id,
            'name' => 'Brigadeiro', 'price' => 45,
        ]);
    }

    public function test_selecting_a_parent_category_aggregates_products_from_its_subcategories(): void
    {
        $component = Livewire::test(ProductCatalogSelector::class)
            ->call('selectCategory', $this->root->id);

        $names = $component->instance()->products->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Calabresa', 'Brigadeiro'], $names);
    }

    public function test_selecting_a_subcategory_narrows_the_list_to_that_subcategory(): void
    {
        $component = Livewire::test(ProductCatalogSelector::class)
            ->call('selectCategory', $this->root->id)
            ->call('selectSubcategory', $this->salgadas->id);

        $this->assertSame(['Calabresa'], $component->instance()->products->pluck('name')->all());
    }

    public function test_switching_root_category_resets_the_selected_subcategory(): void
    {
        Livewire::test(ProductCatalogSelector::class)
            ->call('selectCategory', $this->root->id)
            ->call('selectSubcategory', $this->salgadas->id)
            ->assertSet('subcategoryId', $this->salgadas->id)
            ->call('selectCategory', $this->root->id)
            ->assertSet('subcategoryId', null);
    }

    public function test_category_bar_exposes_navigation_thumbnail_from_first_product_with_photo(): void
    {
        $component = Livewire::test(ProductCatalogSelector::class);

        $root = $component->instance()->categories->firstWhere('id', $this->root->id);

        $this->assertSame($this->calabresa->image_url, $root->nav_thumbnail_url);
    }

    public function test_products_carry_their_category_for_the_card_badge(): void
    {
        $component = Livewire::test(ProductCatalogSelector::class)
            ->call('selectCategory', $this->root->id);

        $calabresa = $component->instance()->products->firstWhere('name', 'Calabresa');

        $this->assertTrue($calabresa->relationLoaded('category'));
        $this->assertSame('Salgadas', $calabresa->category->name);
    }
}

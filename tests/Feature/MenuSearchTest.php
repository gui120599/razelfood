<?php

namespace Tests\Feature;

use App\Livewire\Menu;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RF-10: busca textual por nome de produto no cardápio público. Respeita
 * visibilidade/estoque (App\Livewire\Menu::visibleProducts) e o isolamento
 * por tenant (TenantScope).
 */
class MenuSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

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

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'display_order' => 1,
            'show_in_menu' => true,
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ], $attributes));
    }

    public function test_search_matches_products_by_name(): void
    {
        $this->product(['name' => 'Pizza Calabresa']);
        $this->product(['name' => 'Pizza Marguerita']);

        Livewire::test(Menu::class)
            ->set('search', 'calab')
            ->assertSee('Pizza Calabresa')
            ->assertDontSee('Pizza Marguerita');
    }

    public function test_search_ignores_terms_shorter_than_two_characters(): void
    {
        $this->product(['name' => 'Pizza Calabresa']);

        $component = Livewire::test(Menu::class)->set('search', 'c');

        $this->assertFalse($component->instance()->isSearching());
    }

    public function test_search_excludes_hidden_and_out_of_stock_products(): void
    {
        $this->product(['name' => 'Pizza Oculta', 'is_visible' => false]);
        $this->product(['name' => 'Pizza Sem Estoque', 'controls_stock' => true, 'stock_quantity' => 0]);
        $this->product(['name' => 'Pizza Disponível']);

        Livewire::test(Menu::class)
            ->set('search', 'pizza')
            ->assertSee('Pizza Disponível')
            ->assertDontSee('Pizza Oculta')
            ->assertDontSee('Pizza Sem Estoque');
    }

    public function test_search_never_returns_products_from_another_tenant(): void
    {
        $this->product(['name' => 'Pizza do Tenant A']);

        $other = Tenant::create([
            'name' => 'Outro',
            'slug' => 'outro',
            'whatsapp_number' => '5511888887777',
        ]);
        $otherCategory = Category::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'name' => 'Pizzas',
            'display_order' => 1,
            'show_in_menu' => true,
        ]);
        Product::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'category_id' => $otherCategory->id,
            'name' => 'Pizza do Tenant B',
            'price' => 30,
        ]);

        Livewire::test(Menu::class)
            ->set('search', 'pizza')
            ->assertSee('Pizza do Tenant A')
            ->assertDontSee('Pizza do Tenant B');
    }

    public function test_empty_search_shows_the_not_found_message(): void
    {
        $this->product(['name' => 'Calabresa']);

        Livewire::test(Menu::class)
            ->set('search', 'sushi')
            ->assertSee('Nenhum produto encontrado');
    }
}

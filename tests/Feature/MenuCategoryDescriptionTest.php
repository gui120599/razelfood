<?php

namespace Tests\Feature;

use App\Livewire\Menu;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Descrição opcional de categoria no cardápio público: aparece abaixo do
 * nome da categoria (e da subcategoria) apenas quando o lojista preenche o
 * texto E liga o toggle `show_description_in_menu`.
 */
class MenuCategoryDescriptionTest extends TestCase
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

    private function categoryWithProduct(array $attributes): Category
    {
        $category = Category::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizza P',
            'display_order' => 1,
            'show_in_menu' => true,
        ], $attributes));

        Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'name' => 'Calabresa',
            'price' => 40,
        ]);

        return $category;
    }

    public function test_shows_the_description_when_the_toggle_is_on_and_the_text_is_filled(): void
    {
        $this->categoryWithProduct([
            'description' => 'Serve até 2 pessoas',
            'show_description_in_menu' => true,
        ]);

        Livewire::test(Menu::class)
            ->assertSee('Serve até 2 pessoas');
    }

    public function test_hides_the_description_when_the_toggle_is_off(): void
    {
        $this->categoryWithProduct([
            'description' => 'Serve até 2 pessoas',
            'show_description_in_menu' => false,
        ]);

        Livewire::test(Menu::class)
            ->assertDontSee('Serve até 2 pessoas');
    }

    public function test_does_not_break_when_the_toggle_is_on_but_the_description_is_empty(): void
    {
        $this->categoryWithProduct([
            'description' => null,
            'show_description_in_menu' => true,
        ]);

        Livewire::test(Menu::class)
            ->assertOk()
            ->assertSee('Pizza P');
    }

    public function test_shows_the_description_of_a_subcategory(): void
    {
        $parent = $this->categoryWithProduct(['name' => 'Pizzas']);

        $child = Category::create([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $parent->id,
            'name' => 'Pizza P',
            'display_order' => 1,
            'show_in_menu' => true,
            'description' => 'Serve até 2 pessoas',
            'show_description_in_menu' => true,
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $child->id,
            'name' => 'Muçarela',
            'price' => 35,
        ]);

        Livewire::test(Menu::class)
            ->assertSee('Serve até 2 pessoas');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Livewire\Orders\FlavorPickerModal;
use App\Filament\Tenant\Livewire\Orders\ProductCatalogSelector;
use App\Filament\Tenant\Livewire\Sales\SaleProductSelector;
use App\Filament\Tenant\Resources\Products\Pages\CreateProduct;
use App\Filament\Tenant\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * "Mostrar no cardápio do garçom" (uso interno, como no Pizzaria-App): decide o que as telas
 * internas listam, independente da visibilidade no cardápio público.
 */
class ProductWaiterMenuTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeaturesAndPdv()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));

        $this->category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::create(array_merge(['tenant_id' => $this->tenant->id, 'category_id' => $this->category->id, 'name' => $name, 'price' => 40], $attributes));
    }

    public function test_a_new_product_is_listed_for_the_waiter_by_default(): void
    {
        $this->assertTrue($this->product('Calabresa')->fresh()->show_in_waiter_menu);
    }

    public function test_the_product_form_has_the_toggle_on_by_default_and_saves_it(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertFormSet(['show_in_waiter_menu' => true])
            ->fillForm(['category_id' => $this->category->id, 'name' => 'Interno', 'price' => '10,00', 'show_in_waiter_menu' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(Product::where('name', 'Interno')->sole()->show_in_waiter_menu);

        $product = Product::where('name', 'Interno')->sole();
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['show_in_waiter_menu' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($product->fresh()->show_in_waiter_menu);
    }

    public function test_the_sale_selector_lists_only_products_marked_for_the_waiter(): void
    {
        $this->product('Calabresa');
        $this->product('Produto Escondido', ['show_in_waiter_menu' => false]);
        $other = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Só Escondidos', 'display_order' => 2]);
        $this->product('Outro Escondido', ['category_id' => $other->id, 'show_in_waiter_menu' => false]);

        Livewire::test(SaleProductSelector::class)
            ->assertSee('Calabresa')
            ->assertDontSee('Produto Escondido')
            ->assertDontSee('Outro Escondido')
            ->assertDontSee('Só Escondidos');
    }

    public function test_the_order_screen_catalog_and_flavor_picker_follow_the_same_flag(): void
    {
        $this->product('Calabresa');
        $hidden = $this->product('Produto Escondido', ['show_in_waiter_menu' => false]);

        Livewire::test(ProductCatalogSelector::class)
            ->assertSee('Calabresa')
            ->assertDontSee('Produto Escondido');

        $picker = Livewire::test(FlavorPickerModal::class);
        $picker->set('comboBuilder.category_id', $this->category->id);
        $names = $picker->instance()->availableFlavors->pluck('name')->all();

        $this->assertContains('Calabresa', $names);
        $this->assertNotContains($hidden->name, $names);
    }

    public function test_a_product_hidden_from_the_public_menu_can_still_be_offered_to_the_waiter(): void
    {
        $this->product('Só Interno', ['is_visible' => false, 'show_in_waiter_menu' => true]);

        Livewire::test(SaleProductSelector::class)->assertSee('Só Interno');
        Livewire::test(ProductCatalogSelector::class)->assertSee('Só Interno');
    }

    public function test_the_public_menu_ignores_the_waiter_flag(): void
    {
        $this->product('Só Cardápio', ['is_visible' => true, 'show_in_waiter_menu' => false]);

        $this->assertSame(1, Product::query()->where('is_visible', true)->where('name', 'Só Cardápio')->count());
        Livewire::test(SaleProductSelector::class)->assertDontSee('Só Cardápio');
    }

    public function test_the_waiter_menu_never_lists_another_tenants_products(): void
    {
        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'status' => TenantStatus::Active, 'whatsapp_number' => '5511988888888', 'plan_id' => $this->planWithAllFeaturesAndPdv()->id]);
        $otherCategory = Category::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Cat Alheia', 'display_order' => 1]);
        Product::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'category_id' => $otherCategory->id, 'name' => 'Produto Alheio', 'price' => 10]);

        Livewire::test(SaleProductSelector::class)->assertDontSee('Produto Alheio')->assertDontSee('Cat Alheia');
    }
}

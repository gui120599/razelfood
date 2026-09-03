<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Bulk action "Replicar para outra categoria" na listagem de produtos:
 * copia os produtos selecionados para a categoria/subcategoria escolhida,
 * mantendo os originais e levando os adicionais junto.
 */
class ProductBulkReplicateTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $pizzas;

    private Category $promocoes;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));

        $this->pizzas = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
        $this->promocoes = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Promoções', 'display_order' => 2]);
    }

    public function test_replicates_selected_products_into_the_chosen_category_keeping_the_originals(): void
    {
        $calabresa = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40, 'sales_count' => 12]);
        $mussarela = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Mussarela', 'price' => 38]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$calabresa->id, $mussarela->id])
            ->callAction(TestAction::make('replicateToCategory')->table()->bulk(), [
                'category_id' => $this->promocoes->id,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(2, Product::where('category_id', $this->pizzas->id)->count());
        $this->assertSame(2, Product::where('category_id', $this->promocoes->id)->count());

        $copy = Product::where('category_id', $this->promocoes->id)->where('name', 'Calabresa')->first();
        $this->assertNotNull($copy);
        $this->assertEquals(40.0, (float) $copy->price);
        $this->assertEquals(0.0, (float) $copy->sales_count);
        $this->assertNotSame($calabresa->id, $copy->id);
    }

    public function test_replicated_product_carries_its_addons(): void
    {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);
        $bacon = Addon::create(['tenant_id' => $this->tenant->id, 'name' => 'Bacon extra', 'price' => 6]);
        $product->addons()->attach($bacon->id, ['price' => 5, 'max_quantity' => 2]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('replicateToCategory')->table()->bulk(), [
                'category_id' => $this->promocoes->id,
            ])
            ->assertHasNoActionErrors();

        $copy = Product::where('category_id', $this->promocoes->id)->first();
        $this->assertEqualsCanonicalizing([$bacon->id], $copy->addons->pluck('id')->all());
        $this->assertEquals(5.0, (float) $copy->addons->first()->pivot->price);
        $this->assertSame(2, $copy->addons->first()->pivot->max_quantity);
    }

    public function test_replicated_product_carries_its_gifts(): void
    {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'gift_product_id' => $soda->id, 'quantity' => 2, 'is_active' => true, 'flavor_counts' => [1]]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('replicateToCategory')->table()->bulk(), [
                'category_id' => $this->promocoes->id,
            ])
            ->assertHasNoActionErrors();

        $copy = Product::where('category_id', $this->promocoes->id)->where('name', 'Calabresa')->first();
        $this->assertEqualsCanonicalizing([$soda->id], $copy->gifts->pluck('id')->all());
        $this->assertSame(2, (int) $copy->gifts->first()->pivot->quantity);
        $this->assertEqualsCanonicalizing([1], $copy->gifts->first()->pivot->flavor_counts);
    }

    public function test_target_category_is_required(): void
    {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('replicateToCategory')->table()->bulk(), [
                'category_id' => null,
            ])
            ->assertHasActionErrors(['category_id' => 'required']);

        $this->assertSame(1, Product::count());
    }
}

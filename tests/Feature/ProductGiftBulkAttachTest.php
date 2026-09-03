<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
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
 * Bulk action "Vincular brinde" na listagem de produtos (RN-53): vincula o
 * mesmo produto-brinde a vários produtos principais de uma vez; atualiza o
 * vínculo se já existir; nunca vincula um produto como brinde de si mesmo.
 */
class ProductGiftBulkAttachTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $pizzas;

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
    }

    public function test_links_the_same_gift_to_every_selected_product(): void
    {
        $calabresa = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);
        $mussarela = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Mussarela', 'price' => 38]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$calabresa->id, $mussarela->id])
            ->callAction(TestAction::make('attachGift')->table()->bulk(), [
                'gift_product_id' => $soda->id,
                'quantity' => 2,
                'is_active' => true,
                'flavor_counts' => [1, 2],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        foreach ([$calabresa, $mussarela] as $product) {
            $pivot = ProductGift::where('product_id', $product->id)->where('gift_product_id', $soda->id)->first();
            $this->assertNotNull($pivot);
            $this->assertSame($this->tenant->id, $pivot->tenant_id);
            $this->assertSame(2, (int) $pivot->quantity);
            $this->assertTrue($pivot->is_active);
            $this->assertEqualsCanonicalizing([1, 2], $pivot->flavor_counts);
        }
    }

    public function test_updates_an_existing_link_instead_of_duplicating(): void
    {
        $calabresa = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);
        ProductGift::create(['tenant_id' => $this->tenant->id, 'product_id' => $calabresa->id, 'gift_product_id' => $soda->id, 'quantity' => 1, 'is_active' => true]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$calabresa->id])
            ->callAction(TestAction::make('attachGift')->table()->bulk(), [
                'gift_product_id' => $soda->id,
                'quantity' => 3,
                'is_active' => false,
                'flavor_counts' => [],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, ProductGift::where('product_id', $calabresa->id)->where('gift_product_id', $soda->id)->count());
        $pivot = ProductGift::where('product_id', $calabresa->id)->where('gift_product_id', $soda->id)->first();
        $this->assertSame(3, (int) $pivot->quantity);
        $this->assertFalse($pivot->is_active);
        $this->assertNull($pivot->flavor_counts);
    }

    public function test_a_product_is_never_linked_as_a_gift_of_itself(): void
    {
        $calabresa = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);
        $soda = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$calabresa->id, $soda->id])
            ->callAction(TestAction::make('attachGift')->table()->bulk(), [
                'gift_product_id' => $soda->id,
                'quantity' => 1,
                'is_active' => true,
                'flavor_counts' => [],
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('product_gift', ['product_id' => $soda->id, 'gift_product_id' => $soda->id]);
        $this->assertDatabaseHas('product_gift', ['product_id' => $calabresa->id, 'gift_product_id' => $soda->id]);
    }

    public function test_gift_product_is_required(): void
    {
        $calabresa = Product::create(['tenant_id' => $this->tenant->id, 'category_id' => $this->pizzas->id, 'name' => 'Calabresa', 'price' => 40]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$calabresa->id])
            ->callAction(TestAction::make('attachGift')->table()->bulk(), [
                'gift_product_id' => null,
                'quantity' => 1,
                'is_active' => true,
            ])
            ->assertHasActionErrors(['gift_product_id' => 'required']);

        $this->assertSame(0, ProductGift::count());
    }
}

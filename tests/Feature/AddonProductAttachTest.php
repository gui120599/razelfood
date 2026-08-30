<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\EditProduct;
use App\Filament\Tenant\Resources\Products\RelationManagers\AddonsRelationManager;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class AddonProductAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização do Shield não é o alvo deste teste — ver testes de painel dedicados a isso.
        Gate::before(fn () => true);
    }

    /**
     * Product::addons() e Addon::products() precisam existir nos dois
     * sentidos — Filament infere a relação inversa a partir do model pai
     * (Addon -> "products") e a usa em AttachAction::getRecordSelect() pra
     * excluir adicionais já anexados das opções; sem o método no model,
     * abrir o select do Attach quebra com BadMethodCallException (mesma
     * classe de bug já documentada pra Product::flashPromotions()).
     */
    public function test_attach_record_select_can_resolve_the_inverse_relationship(): void
    {
        [$tenant, $product, $addon] = $this->makeTenantProductAndAddon();

        $instance = Livewire::test(AddonsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->instance();

        $relation = $instance->getTable()->getInverseRelationshipFor($addon);

        $this->assertInstanceOf(BelongsToMany::class, $relation);
    }

    public function test_attaching_an_addon_fills_tenant_id_and_optional_price_override_on_the_pivot(): void
    {
        [$tenant, $product, $addon] = $this->makeTenantProductAndAddon();

        Livewire::test(AddonsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callAction(TestAction::make('attach')->table(), [
                'recordId' => $addon->id,
                'price' => 8.00,
                'max_quantity' => 3,
            ])
            ->assertHasNoActionErrors();

        $pivot = ProductAddon::query()
            ->where('product_id', $product->id)
            ->where('addon_id', $addon->id)
            ->first();

        $this->assertNotNull($pivot, 'O adicional deveria ter sido anexado ao produto.');
        $this->assertSame($tenant->id, $pivot->tenant_id);
        $this->assertEquals(8.00, (float) $pivot->price);
        $this->assertSame(3, $pivot->max_quantity);
    }

    public function test_attaching_an_addon_without_price_override_leaves_pivot_price_null(): void
    {
        [, $product, $addon] = $this->makeTenantProductAndAddon();

        Livewire::test(AddonsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callAction(TestAction::make('attach')->table(), [
                'recordId' => $addon->id,
                'price' => null,
                'max_quantity' => null,
            ])
            ->assertHasNoActionErrors();

        $pivot = ProductAddon::query()
            ->where('product_id', $product->id)
            ->where('addon_id', $addon->id)
            ->first();

        $this->assertNotNull($pivot);
        $this->assertNull($pivot->price);
        $this->assertNull($pivot->max_quantity);
    }

    /**
     * @return array{0: Tenant, 1: Product, 2: Addon}
     */
    private function makeTenantProductAndAddon(): array
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($tenant);
        URL::defaults(['tenant' => $tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pizzas',
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Frango com Bacon',
            'price' => 45.00,
        ]);

        $addon = Addon::create([
            'tenant_id' => $tenant->id,
            'name' => 'Bacon extra',
            'price' => 6.00,
        ]);

        return [$tenant, $product, $addon];
    }
}

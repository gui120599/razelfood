<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\FlashPromotions\Pages\EditFlashPromotion;
use App\Filament\Tenant\Resources\FlashPromotions\RelationManagers\ProductsRelationManager;
use App\Models\Category;
use App\Models\FlashPromotion;
use App\Models\FlashPromotionProduct;
use App\Models\Product;
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

class FlashPromotionProductAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização do Shield não é o alvo deste teste — ver testes de painel dedicados a isso.
        Gate::before(fn () => true);
    }

    /**
     * Product::flashPromotions() estava faltando. Filament infere o nome da relação
     * inversa a partir do model pai (FlashPromotion -> "flashPromotions") e a usa em
     * AttachAction::getRecordSelect() para excluir produtos já anexados das opções —
     * sem o método no model, abrir o select do Attach quebra com BadMethodCallException.
     */
    public function test_attach_record_select_can_resolve_the_inverse_relationship(): void
    {
        [$tenant, $product, $promotion] = $this->makeTenantProductAndPromotion();

        $instance = Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $promotion,
            'pageClass' => EditFlashPromotion::class,
        ])->instance();

        $relation = $instance->getTable()->getInverseRelationshipFor($product);

        $this->assertInstanceOf(BelongsToMany::class, $relation);
    }

    public function test_attaching_a_product_fills_tenant_id_on_the_pivot(): void
    {
        [$tenant, $product, $promotion] = $this->makeTenantProductAndPromotion();

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $promotion,
            'pageClass' => EditFlashPromotion::class,
        ])
            ->callAction(TestAction::make('attach')->table(), [
                'recordId' => $product->id,
                'promotional_price' => 19.90,
                'total_quantity' => 5,
            ])
            ->assertHasNoActionErrors();

        $pivot = FlashPromotionProduct::query()
            ->where('flash_promotion_id', $promotion->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($pivot, 'O produto deveria ter sido anexado à promoção.');
        $this->assertSame($tenant->id, $pivot->tenant_id);
        $this->assertEquals(19.90, (float) $pivot->promotional_price);
        $this->assertSame(5, $pivot->total_quantity);
    }

    /**
     * @return array{0: Tenant, 1: Product, 2: FlashPromotion}
     */
    private function makeTenantProductAndPromotion(): array
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
            'name' => 'Pizza Muçarela',
            'price' => 45.00,
        ]);

        $promotion = FlashPromotion::create([
            'tenant_id' => $tenant->id,
            'name' => 'Happy Hour',
            'is_active' => true,
        ]);

        return [$tenant, $product, $promotion];
    }
}

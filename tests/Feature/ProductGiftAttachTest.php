<?php

namespace Tests\Feature;

use App\Enums\GiftAwardMode;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\EditProduct;
use App\Filament\Tenant\Resources\Products\RelationManagers\GiftsRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGift;
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

/**
 * Configuração de brindes na aba "Brindes do produto" do ProductResource
 * (RN-53). gifts() é um self-join Product↔Product, então o RelationManager
 * declara $inverseRelationship = 'giftedByProducts' explicitamente.
 */
class ProductGiftAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
    }

    public function test_attach_record_select_can_resolve_the_inverse_relationship(): void
    {
        [, $product, $gift] = $this->makeTenantProductAndGift();

        $instance = Livewire::test(GiftsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->instance();

        $relation = $instance->getTable()->getInverseRelationshipFor($gift);

        $this->assertInstanceOf(BelongsToMany::class, $relation);
    }

    public function test_attaching_a_gift_fills_tenant_id_quantity_and_active_flag_on_the_pivot(): void
    {
        [$tenant, $product, $gift] = $this->makeTenantProductAndGift();

        Livewire::test(GiftsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callAction(TestAction::make('attach')->table(), [
                'recordId' => [$gift->id],
                'quantity' => 2,
                'award_mode' => 'per_order',
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $pivot = ProductGift::query()
            ->where('product_id', $product->id)
            ->where('gift_product_id', $gift->id)
            ->first();

        $this->assertNotNull($pivot);
        $this->assertSame($tenant->id, $pivot->tenant_id);
        $this->assertSame(2, (int) $pivot->quantity);
        $this->assertTrue($pivot->is_active);
        $this->assertSame(GiftAwardMode::PerOrder, $pivot->award_mode);
    }

    public function test_attaching_a_gift_defaults_award_mode_to_per_quantity(): void
    {
        [, $product, $gift] = $this->makeTenantProductAndGift();

        Livewire::test(GiftsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callAction(TestAction::make('attach')->table(), [
                'recordId' => [$gift->id],
                'quantity' => 1,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $pivot = ProductGift::where('product_id', $product->id)->where('gift_product_id', $gift->id)->first();
        $this->assertSame(GiftAwardMode::PerQuantity, $pivot->award_mode);
    }

    public function test_the_product_cannot_be_offered_as_a_gift_of_itself(): void
    {
        [, $product] = $this->makeTenantProductAndGift();

        Livewire::test(GiftsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callAction(TestAction::make('attach')->table(), [
                'recordId' => [$product->id],
                'quantity' => 1,
                'is_active' => true,
            ]);

        $this->assertDatabaseMissing('product_gift', [
            'product_id' => $product->id,
            'gift_product_id' => $product->id,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Product, 2: Product}
     */
    private function makeTenantProductAndGift(): array
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

        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Pizzas']);

        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Pizza Calabresa', 'price' => 65]);
        $gift = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Guaraná 1,5L', 'price' => 12]);

        return [$tenant, $product, $gift];
    }
}

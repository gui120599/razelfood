<?php

namespace Tests\Feature;

use App\Actions\Products\AdjustProductsPrice;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
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
 * Bulk action "Ajustar preço" na listagem de produtos: define valor fixo,
 * porcentagem ou valor em R$ sobre os produtos selecionados.
 */
class ProductBulkAdjustPriceTest extends TestCase
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
            'plan_id' => $this->planWithAllFeatures()->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));

        $this->category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas', 'display_order' => 1]);
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

    private function callAdjust(array $products, array $data): void
    {
        Livewire::test(ListProducts::class)
            ->selectTableRecords(collect($products)->pluck('id')->all())
            ->callAction(TestAction::make('adjustPrice')->table()->bulk(), $data)
            ->assertHasNoActionErrors();
    }

    public function test_set_a_fixed_price(): void
    {
        $a = $this->product(['name' => 'A', 'price' => 40]);
        $b = $this->product(['name' => 'B', 'price' => 55]);

        $this->callAdjust([$a, $b], ['mode' => 'set', 'value' => '29,90']);

        $this->assertEquals(29.90, (float) $a->fresh()->price);
        $this->assertEquals(29.90, (float) $b->fresh()->price);
    }

    public function test_increase_by_percent(): void
    {
        $product = $this->product(['price' => 40]);

        $this->callAdjust([$product], ['mode' => 'percent', 'direction' => 'increase', 'value' => '10,00']);

        $this->assertEquals(44.00, (float) $product->fresh()->price);
    }

    public function test_decrease_by_amount_never_goes_below_zero(): void
    {
        $product = $this->product(['price' => 8]);

        $this->callAdjust([$product], ['mode' => 'amount', 'direction' => 'decrease', 'value' => '10,00']);

        $this->assertEquals(0.00, (float) $product->fresh()->price);
    }

    public function test_optionally_applies_to_the_promotional_price(): void
    {
        $product = $this->product(['price' => 40, 'promotional_price' => 30]);

        $this->callAdjust([$product], [
            'mode' => 'percent',
            'direction' => 'decrease',
            'value' => '50,00',
            'apply_to_promotional' => true,
        ]);

        $fresh = $product->fresh();
        $this->assertEquals(20.00, (float) $fresh->price);
        $this->assertEquals(15.00, (float) $fresh->promotional_price);
    }

    public function test_promotional_price_is_untouched_by_default(): void
    {
        $product = $this->product(['price' => 40, 'promotional_price' => 30]);

        $this->callAdjust([$product], ['mode' => 'set', 'value' => '50,00']);

        $fresh = $product->fresh();
        $this->assertEquals(50.00, (float) $fresh->price);
        $this->assertEquals(30.00, (float) $fresh->promotional_price);
    }

    public function test_value_is_required(): void
    {
        $product = $this->product();

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('adjustPrice')->table()->bulk(), ['mode' => 'set', 'value' => null])
            ->assertHasActionErrors(['value' => 'required']);

        $this->assertEquals(40.00, (float) $product->fresh()->price);
    }

    public function test_action_class_rejects_an_unknown_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AdjustProductsPrice::class)(collect([$this->product()]), 'bogus', 10);
    }
}

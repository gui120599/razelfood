<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\CreateProduct;
use App\Filament\Tenant\Resources\Products\Schemas\ProductForm;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * O select de categoria do form de produto agrupa as subcategorias pela
 * categoria pai — subcategorias de pais diferentes podem ter o mesmo nome.
 */
class ProductCategorySelectTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $salgadas;

    private Category $doces;

    private Category $brotoSalgada;

    private Category $brotoDoce;

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

        $this->salgadas = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas Salgadas', 'display_order' => 1]);
        $this->doces = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas Doces', 'display_order' => 2]);

        $this->brotoSalgada = Category::create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->salgadas->id, 'name' => 'Broto', 'display_order' => 1]);
        $this->brotoDoce = Category::create(['tenant_id' => $this->tenant->id, 'parent_id' => $this->doces->id, 'name' => 'Broto', 'display_order' => 1]);
    }

    public function test_options_are_grouped_by_parent_category(): void
    {
        $select = $this->categorySelect();

        $options = $select->getOptions();

        $this->assertArrayHasKey('Pizzas Salgadas', $options);
        $this->assertArrayHasKey('Pizzas Doces', $options);
        $this->assertSame(
            [$this->salgadas->id => 'Pizzas Salgadas', $this->brotoSalgada->id => 'Broto'],
            $options['Pizzas Salgadas'],
        );
        $this->assertSame(
            [$this->doces->id => 'Pizzas Doces', $this->brotoDoce->id => 'Broto'],
            $options['Pizzas Doces'],
        );
    }

    public function test_root_category_without_children_stays_ungrouped(): void
    {
        $bebidas = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Bebidas', 'display_order' => 3]);

        $options = $this->categorySelect()->getOptions();

        $this->assertSame('Bebidas', $options[$bebidas->id]);
    }

    public function test_can_create_a_product_on_a_same_named_subcategory(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->brotoDoce->id,
                'name' => 'Broto de Chocolate',
                'price' => '30,00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Broto de Chocolate',
            'category_id' => $this->brotoDoce->id,
        ]);
    }

    private function categorySelect(): Select
    {
        $schema = ProductForm::configure(Schema::make(Livewire::test(CreateProduct::class)->instance()));

        return $schema->getComponent(
            fn ($component): bool => $component instanceof Select && $component->getName() === 'category_id',
            withHidden: true,
        );
    }
}

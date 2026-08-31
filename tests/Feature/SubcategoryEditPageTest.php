<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Categories\Pages\EditCategory;
use App\Filament\Tenant\Resources\Categories\Pages\ListCategories;
use App\Filament\Tenant\Resources\Categories\RelationManagers\FlavorQuantityOptionsRelationManager;
use App\Filament\Tenant\Resources\Categories\RelationManagers\SubcategoriesRelationManager;
use App\Models\Category;
use App\Models\FlavorQuantityOption;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Subcategoria ganhou página de edição completa do Resource (com a aba
 * "Quantidades de sabores"), mantendo 1 nível de hierarquia.
 */
class SubcategoryEditPageTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $parent;

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

        $this->parent = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'allows_flavors' => true,
        ]);

        FlavorQuantityOption::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->parent->id,
            'label' => 'Meio a meio',
            'flavor_count' => 2,
            'display_order' => 1,
        ]);
    }

    private function subcategory(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'parent_id' => $this->parent->id,
            'name' => 'Pizzas Salgadas',
            'allows_flavors' => true,
        ], $attributes));
    }

    public function test_subcategory_edit_page_opens(): void
    {
        $sub = $this->subcategory();

        Livewire::test(EditCategory::class, ['record' => $sub->id])
            ->assertOk();
    }

    public function test_subcategory_edit_page_does_not_nest_a_subcategories_manager(): void
    {
        $sub = $this->subcategory();

        Livewire::test(EditCategory::class, ['record' => $sub->id])
            ->assertDontSeeLivewire(SubcategoriesRelationManager::class);
    }

    public function test_flavor_quantity_tab_is_hidden_when_the_subcategory_inherits(): void
    {
        $sub = $this->subcategory(['inherit_flavor_options' => true]);

        Livewire::test(EditCategory::class, ['record' => $sub->id])
            ->assertDontSeeLivewire(FlavorQuantityOptionsRelationManager::class);
    }

    public function test_flavor_quantity_tab_shows_when_the_subcategory_manages_its_own(): void
    {
        $sub = $this->subcategory(['inherit_flavor_options' => false]);

        Livewire::test(EditCategory::class, ['record' => $sub->id])
            ->assertSeeLivewire(FlavorQuantityOptionsRelationManager::class);
    }

    public function test_category_listing_stays_root_only(): void
    {
        $sub = $this->subcategory();

        Livewire::test(ListCategories::class)
            ->assertCanSeeTableRecords([$this->parent])
            ->assertCanNotSeeTableRecords([$sub]);
    }

    public function test_creating_a_subcategory_with_flavors_defaults_to_inheriting(): void
    {
        Livewire::test(SubcategoriesRelationManager::class, [
            'ownerRecord' => $this->parent,
            'pageClass' => EditCategory::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'name' => 'Pizzas Doces',
                'allows_flavors' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('categories', [
            'name' => 'Pizzas Doces',
            'parent_id' => $this->parent->id,
            'inherit_flavor_options' => 1,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
use App\Filament\Tenant\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductTableFilterPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $pizzaCategory;

    private Category $burgerCategory;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $feature = Feature::create(['key' => FeatureKey::CARDAPIO_DIGITAL, 'name' => 'Cardápio Digital', 'is_available' => true]);
        $plan = Plan::create(['name' => 'Essencial', 'slug' => 'essencial']);
        $plan->features()->attach($feature);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
            'plan_id' => $plan->id,
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->pizzaCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas']);
        $this->burgerCategory = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Hambúrgueres']);

        // SeedDefaultTenantRoles não cria as permissões de ProductResource (só
        // Order/Kitchen/OrderSettings/ProductionLine) — num banco de testes
        // "limpo", sem um shield:generate --all já rodado antes, o Admin não
        // tem "ViewAny:Product" mesmo com syncPermissions(Permission::all()),
        // porque a permissão nunca existiu como registro. Criamos aqui do
        // mesmo jeito que SeedDefaultTenantRoles já faz pra outros recursos.
        $productPermissions = FilamentShield::getResources()[ProductResource::class]['permissions'] ?? [];
        collect($productPermissions)
            ->pluck('key')
            ->filter()
            ->each(fn (string $key) => Permission::findOrCreate($key));

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('Admin');
        $this->admin->syncPermissions(Permission::all());
        $this->actingAs($this->admin);
    }

    public function test_filtering_the_table_persists_the_filter_as_a_preference(): void
    {
        Livewire::test(ListProducts::class)->filterTable('category_id', $this->pizzaCategory->id);

        $saved = UserPreference::valueFor($this->admin, 'products.table_filters');

        $this->assertSame($this->pizzaCategory->id, $saved['tableFilters']['category_id']['value'] ?? null);
    }

    public function test_a_fresh_page_load_restores_the_saved_table_filter(): void
    {
        $pizza = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->pizzaCategory->id,
            'name' => 'Pizza Calabresa',
            'price' => 45,
        ]);

        $burger = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $this->burgerCategory->id,
            'name' => 'X-Burguer',
            'price' => 30,
        ]);

        Livewire::test(ListProducts::class)->filterTable('category_id', $this->pizzaCategory->id);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$pizza])
            ->assertCanNotSeeTableRecords([$burger]);
    }
}

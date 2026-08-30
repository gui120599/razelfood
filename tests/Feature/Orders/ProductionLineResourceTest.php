<?php

namespace Tests\Feature\Orders;

use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\ProductionLines\Pages\CreateProductionLine;
use App\Filament\Tenant\Resources\ProductionLines\Pages\EditProductionLine;
use App\Filament\Tenant\Resources\ProductionLines\Pages\ListProductionLines;
use App\Filament\Tenant\Resources\ProductionLines\ProductionLineResource;
use App\Models\Category;
use App\Models\ProductionLine;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

class ProductionLineResourceTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

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
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(SeedDefaultTenantRoles::class)($this->tenant);

        $this->category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Pizzas']);

        $gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $gerente->assignRole('Gerente');
        $this->actingAs($gerente);
    }

    public function test_gerente_can_create_a_production_line_with_categories(): void
    {
        Livewire::test(CreateProductionLine::class)
            ->fillForm([
                'name' => 'Pista de Pizzas',
                'categories' => [$this->category->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('production_lines', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Pista de Pizzas',
        ]);

        $line = ProductionLine::where('name', 'Pista de Pizzas')->firstOrFail();
        $this->assertTrue($line->categories->contains($this->category));
    }

    public function test_gerente_can_edit_a_production_line(): void
    {
        $line = ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Pizzas']);
        $line->categories()->attach($this->category);

        Livewire::test(EditProductionLine::class, ['record' => $line->id])
            ->fillForm(['name' => 'Pista de Pizzas Salgadas'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Pista de Pizzas Salgadas', $line->fresh()->name);
    }

    public function test_list_page_shows_production_lines(): void
    {
        $line = ProductionLine::create(['tenant_id' => $this->tenant->id, 'name' => 'Pista de Pizzas']);

        Livewire::test(ListProductionLines::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$line]);
    }

    public function test_atendente_cannot_access_production_lines_resource(): void
    {
        $atendente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $atendente->assignRole('Atendente');
        $this->actingAs($atendente);

        $this->assertFalse(ProductionLineResource::canViewAny());
    }
}

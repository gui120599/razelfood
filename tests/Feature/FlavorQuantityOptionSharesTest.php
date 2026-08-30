<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\Categories\Pages\EditCategory;
use App\Filament\Tenant\Resources\Categories\RelationManagers\FlavorQuantityOptionsRelationManager;
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
 * O rateio de estoque/vendagem por sabor de combo (RN ligada a
 * App\Actions\Orders\Support\CartStockAndPromotionLedger) vem do % que o
 * Admin configura aqui — o último sabor nunca é digitado, é sempre o resto
 * até 100%, calculado no cliente (afterStateUpdated) e reforçado aqui via
 * teste de integração.
 */
class FlavorQuantityOptionSharesTest extends TestCase
{
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização do Shield não é o alvo deste teste.
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

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pizzas',
            'allows_flavors' => true,
        ]);
    }

    public function test_creating_an_option_auto_fills_equal_shares_summing_to_100(): void
    {
        Livewire::test(FlavorQuantityOptionsRelationManager::class, [
            'ownerRecord' => $this->category,
            'pageClass' => EditCategory::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'label' => 'Três sabores',
                'flavor_count' => 3,
            ])
            ->assertHasNoActionErrors();

        $option = FlavorQuantityOption::where('category_id', $this->category->id)->firstOrFail();

        $this->assertSame([33.33, 33.33, 33.34], $option->flavor_shares);
    }

    public function test_editable_shares_summing_beyond_100_is_rejected(): void
    {
        Livewire::test(FlavorQuantityOptionsRelationManager::class, [
            'ownerRecord' => $this->category,
            'pageClass' => EditCategory::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'label' => 'Três sabores',
                'flavor_count' => 3,
                // 70 + 40 já ultrapassa 100% sozinho, antes de considerar o
                // terceiro sabor — a soma dos EDITÁVEIS é o que é validado.
                'flavor_shares' => [70, 40, 0],
            ])
            ->assertHasActionErrors(['flavor_shares.0', 'flavor_shares.1']);

        $this->assertDatabaseCount('flavor_quantity_options', 0);
    }
}

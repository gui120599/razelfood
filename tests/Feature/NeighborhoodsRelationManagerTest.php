<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Tenant\Resources\DeliveryZones\Pages\EditDeliveryZone;
use App\Filament\Tenant\Resources\DeliveryZones\RelationManagers\NeighborhoodsRelationManager;
use App\Models\City;
use App\Models\DeliveryZone;
use App\Models\DeliveryZoneNeighborhood;
use App\Models\Neighborhood;
use App\Models\State;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class NeighborhoodsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private DeliveryZone $deliveryZone;

    private City $saoPaulo;

    private City $campinas;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização do Shield/gating por feature não é o alvo deste teste.
        Gate::before(fn () => true);

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
        URL::defaults(['tenant' => $this->tenant->slug]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));

        $this->actingAs(User::factory()->create(['tenant_id' => $this->tenant->id]));

        $this->deliveryZone = DeliveryZone::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Centro',
            'delivery_fee' => 5,
        ]);

        // Catálogo global (independente do tenant), como ficaria após a
        // sincronização de localidades do Super Admin.
        $state = State::create(['name' => 'São Paulo', 'uf' => 'SP', 'ibge_code' => 35]);
        $this->saoPaulo = City::create(['state_id' => $state->id, 'name' => 'São Paulo', 'ibge_code' => 3550308]);
        $this->campinas = City::create(['state_id' => $state->id, 'name' => 'Campinas', 'ibge_code' => 3509502]);

        Neighborhood::create(['city_id' => $this->saoPaulo->id, 'name' => 'Vila Mariana']);
        Neighborhood::create(['city_id' => $this->saoPaulo->id, 'name' => 'Sé']);
        Neighborhood::create(['city_id' => $this->campinas->id, 'name' => 'Centro']);
    }

    private function relationManager(): Testable
    {
        return Livewire::test(NeighborhoodsRelationManager::class, [
            'ownerRecord' => $this->deliveryZone,
            'pageClass' => EditDeliveryZone::class,
        ]);
    }

    public function test_changing_city_resets_the_previously_selected_neighborhoods(): void
    {
        $this->relationManager()
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->set('mountedActions.0.data.city', 'sao paulo')
            ->set('mountedActions.0.data.neighborhoods', ['se'])
            ->set('mountedActions.0.data.city', 'campinas')
            ->assertSchemaStateSet(['neighborhoods' => []]);
    }

    public function test_neighborhood_select_is_disabled_until_a_city_is_selected(): void
    {
        $this->relationManager()
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertSchemaComponentExists('neighborhoods', null, fn ($component) => $component->isDisabled());
    }

    public function test_creating_a_neighborhood_from_the_catalog_persists_normalized_city_and_neighborhood(): void
    {
        $this->relationManager()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'city' => 'sao paulo',
                'neighborhoods' => ['vila mariana'],
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('delivery_zone_neighborhoods', [
            'tenant_id' => $this->tenant->id,
            'delivery_zone_id' => $this->deliveryZone->id,
            'city_id' => $this->saoPaulo->id,
            'city' => 'sao paulo',
            'neighborhood' => 'vila mariana',
        ]);
    }

    public function test_creating_several_neighborhoods_at_once_creates_one_row_each(): void
    {
        $this->relationManager()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'city' => 'sao paulo',
                'neighborhoods' => ['vila mariana', 'se'],
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('delivery_zone_neighborhoods', [
            'delivery_zone_id' => $this->deliveryZone->id, 'city' => 'sao paulo', 'neighborhood' => 'vila mariana',
        ]);
        $this->assertDatabaseHas('delivery_zone_neighborhoods', [
            'delivery_zone_id' => $this->deliveryZone->id, 'city' => 'sao paulo', 'neighborhood' => 'se',
        ]);
        $this->assertSame(2, $this->deliveryZone->neighborhoods()->count());
    }

    public function test_last_city_is_remembered_and_preselected_on_the_next_create(): void
    {
        $component = $this->relationManager()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'city' => 'sao paulo',
                'neighborhoods' => ['vila mariana'],
            ])
            ->assertHasNoActionErrors()
            ->assertSet('lastCity', 'sao paulo');

        $component
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertSchemaStateSet(['city' => 'sao paulo']);
    }

    public function test_neighborhood_from_a_different_city_is_rejected(): void
    {
        // "Centro" existe no catálogo, mas pertence a Campinas — não pode
        // ser salvo com city=sao paulo mesmo que o valor exista em algum
        // lugar da base.
        $this->relationManager()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'city' => 'sao paulo',
                'neighborhoods' => ['centro'],
            ])
            ->assertHasActionErrors(['neighborhoods']);
    }

    public function test_duplicate_neighborhood_for_the_same_city_is_rejected_with_a_friendly_message(): void
    {
        $otherZone = DeliveryZone::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Zona Sul',
            'delivery_fee' => 8,
        ]);

        DeliveryZoneNeighborhood::create([
            'tenant_id' => $this->tenant->id,
            'delivery_zone_id' => $otherZone->id,
            'neighborhood' => 'Vila Mariana',
            'city' => 'São Paulo',
        ]);

        $this->relationManager()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'city' => 'sao paulo',
                'neighborhoods' => ['vila mariana'],
            ])
            ->assertHasActionErrors(['neighborhoods']);
    }

    public function test_editing_a_neighborhood_does_not_trigger_its_own_duplicate_validation(): void
    {
        $neighborhood = DeliveryZoneNeighborhood::create([
            'tenant_id' => $this->tenant->id,
            'delivery_zone_id' => $this->deliveryZone->id,
            'neighborhood' => 'Vila Mariana',
            'city' => 'São Paulo',
        ]);

        $this->relationManager()
            ->callAction(TestAction::make('edit')->table($neighborhood), [
                'city' => 'sao paulo',
                'neighborhood' => 'vila mariana',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($this->saoPaulo->id, $neighborhood->refresh()->city_id);
    }
}

<?php

namespace Tests\Feature\Central;

use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\Cities\RelationManagers\NeighborhoodsRelationManager;
use App\Models\City;
use App\Models\LocationSync;
use App\Models\Neighborhood;
use App\Models\State;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CityResourceTest extends TestCase
{
    use RefreshDatabase;

    private State $goias;

    private State $saoPaulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        $this->goias = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
        $this->saoPaulo = State::create(['name' => 'São Paulo', 'uf' => 'SP', 'ibge_code' => 35]);
    }

    public function test_can_create_a_city(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm(['state_id' => $this->goias->id, 'name' => 'Goiânia', 'ibge_code' => 5208707])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('cities', [
            'state_id' => $this->goias->id,
            'name' => 'Goiânia',
            'normalized_name' => 'goiania',
            'ibge_code' => 5208707,
        ]);
    }

    public function test_name_must_be_unique_within_the_same_state_ignoring_case_and_accent(): void
    {
        City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);

        Livewire::test(CreateCity::class)
            ->fillForm(['state_id' => $this->goias->id, 'name' => 'GOIANIA'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_same_city_name_is_allowed_in_a_different_state(): void
    {
        City::create(['state_id' => $this->goias->id, 'name' => 'Bom Jesus']);

        Livewire::test(CreateCity::class)
            ->fillForm(['state_id' => $this->saoPaulo->id, 'name' => 'Bom Jesus'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_can_edit_a_city(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiana']);

        Livewire::test(EditCity::class, ['record' => $city->id])
            ->fillForm(['name' => 'Goiânia'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Goiânia', $city->fresh()->name);
    }

    public function test_editing_a_city_does_not_trigger_its_own_duplicate_validation(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);

        Livewire::test(EditCity::class, ['record' => $city->id])
            ->fillForm(['name' => 'Goiânia'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_deleting_a_city_with_neighborhoods_is_blocked(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);
        Neighborhood::create(['city_id' => $city->id, 'name' => 'Setor Bueno']);

        Livewire::test(EditCity::class, ['record' => $city->id])
            ->callAction('delete');

        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_deleting_a_city_with_location_sync_history_is_blocked(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);
        LocationSync::create([
            'state_id' => $this->goias->id, 'city_id' => $city->id,
            'cep_start' => 1, 'cep_end' => 10, 'total_ceps' => 10,
        ]);

        Livewire::test(EditCity::class, ['record' => $city->id])
            ->callAction('delete');

        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_can_delete_a_city_without_dependents(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);

        Livewire::test(EditCity::class, ['record' => $city->id])
            ->callAction('delete');

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    public function test_neighborhoods_relation_manager_can_create_a_neighborhood_for_the_city(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);

        Livewire::test(NeighborhoodsRelationManager::class, [
            'ownerRecord' => $city,
            'pageClass' => EditCity::class,
        ])
            ->callAction(TestAction::make('create')->table(), ['name' => 'Setor Bueno'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('neighborhoods', ['city_id' => $city->id, 'normalized_name' => 'setor bueno']);
    }

    public function test_neighborhoods_relation_manager_rejects_duplicate_name_for_the_same_city(): void
    {
        $city = City::create(['state_id' => $this->goias->id, 'name' => 'Goiânia']);
        Neighborhood::create(['city_id' => $city->id, 'name' => 'Setor Bueno']);

        Livewire::test(NeighborhoodsRelationManager::class, [
            'ownerRecord' => $city,
            'pageClass' => EditCity::class,
        ])
            ->callAction(TestAction::make('create')->table(), ['name' => 'setor bueno'])
            ->assertHasActionErrors(['name']);
    }

    public function test_index_page_is_accessible(): void
    {
        $this->get(CityResource::getUrl('index'))->assertOk();
    }
}

<?php

namespace Tests\Feature\Central;

use App\Filament\Resources\Neighborhoods\Pages\CreateNeighborhood;
use App\Filament\Resources\Neighborhoods\Pages\EditNeighborhood;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\State;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NeighborhoodResourceTest extends TestCase
{
    use RefreshDatabase;

    private City $goiania;

    private City $campinas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        $state = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
        $this->goiania = City::create(['state_id' => $state->id, 'name' => 'Goiânia']);
        $this->campinas = City::create(['state_id' => $state->id, 'name' => 'Campinas']);
    }

    public function test_can_create_a_neighborhood(): void
    {
        Livewire::test(CreateNeighborhood::class)
            ->fillForm(['city_id' => $this->goiania->id, 'name' => 'Setor Bueno'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('neighborhoods', [
            'city_id' => $this->goiania->id,
            'name' => 'Setor Bueno',
            'normalized_name' => 'setor bueno',
        ]);
    }

    public function test_name_must_be_unique_within_the_same_city_ignoring_case_and_accent(): void
    {
        Neighborhood::create(['city_id' => $this->goiania->id, 'name' => 'Setor Bueno']);

        Livewire::test(CreateNeighborhood::class)
            ->fillForm(['city_id' => $this->goiania->id, 'name' => 'SETOR BUENO'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_same_neighborhood_name_is_allowed_in_a_different_city(): void
    {
        Neighborhood::create(['city_id' => $this->goiania->id, 'name' => 'Centro']);

        Livewire::test(CreateNeighborhood::class)
            ->fillForm(['city_id' => $this->campinas->id, 'name' => 'Centro'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_can_edit_a_neighborhood(): void
    {
        $neighborhood = Neighborhood::create(['city_id' => $this->goiania->id, 'name' => 'Setor Buneo']);

        Livewire::test(EditNeighborhood::class, ['record' => $neighborhood->id])
            ->fillForm(['name' => 'Setor Bueno'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Setor Bueno', $neighborhood->fresh()->name);
    }

    public function test_editing_a_neighborhood_does_not_trigger_its_own_duplicate_validation(): void
    {
        $neighborhood = Neighborhood::create(['city_id' => $this->goiania->id, 'name' => 'Setor Bueno']);

        Livewire::test(EditNeighborhood::class, ['record' => $neighborhood->id])
            ->fillForm(['name' => 'Setor Bueno'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_can_change_a_neighborhood_to_a_different_city(): void
    {
        $neighborhood = Neighborhood::create(['city_id' => $this->goiania->id, 'name' => 'Centro']);

        Livewire::test(EditNeighborhood::class, ['record' => $neighborhood->id])
            ->fillForm(['city_id' => $this->campinas->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->campinas->id, $neighborhood->fresh()->city_id);
    }

    public function test_can_delete_a_neighborhood(): void
    {
        $neighborhood = Neighborhood::create(['city_id' => $this->goiania->id, 'name' => 'Setor Bueno']);

        Livewire::test(EditNeighborhood::class, ['record' => $neighborhood->id])
            ->callAction('delete');

        $this->assertDatabaseMissing('neighborhoods', ['id' => $neighborhood->id]);
    }
}

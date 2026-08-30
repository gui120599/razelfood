<?php

namespace Tests\Feature\Central;

use App\Filament\Resources\States\Pages\CreateState;
use App\Filament\Resources\States\Pages\EditState;
use App\Models\City;
use App\Models\State;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StateResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));
    }

    public function test_can_create_a_state(): void
    {
        Livewire::test(CreateState::class)
            ->fillForm(['name' => 'Goiás', 'uf' => 'go', 'ibge_code' => 52])
            ->call('create')
            ->assertHasNoFormErrors();

        // uf sempre maiúsculo, independente do que foi digitado.
        $this->assertDatabaseHas('states', ['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
    }

    public function test_uf_must_be_unique(): void
    {
        State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);

        Livewire::test(CreateState::class)
            ->fillForm(['name' => 'Outro Goiás', 'uf' => 'GO'])
            ->call('create')
            ->assertHasFormErrors(['uf' => 'unique']);
    }

    public function test_ibge_code_must_be_unique(): void
    {
        State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);

        Livewire::test(CreateState::class)
            ->fillForm(['name' => 'Outro', 'uf' => 'XX', 'ibge_code' => 52])
            ->call('create')
            ->assertHasFormErrors(['ibge_code' => 'unique']);
    }

    public function test_can_edit_a_state(): void
    {
        $state = State::create(['name' => 'Goias', 'uf' => 'GO', 'ibge_code' => 52]);

        Livewire::test(EditState::class, ['record' => $state->id])
            ->fillForm(['name' => 'Goiás'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Goiás', $state->fresh()->name);
    }

    public function test_deleting_a_state_with_cities_is_blocked(): void
    {
        $state = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
        City::create(['state_id' => $state->id, 'name' => 'Goiânia', 'ibge_code' => 5208707]);

        Livewire::test(EditState::class, ['record' => $state->id])
            ->callAction('delete');

        $this->assertDatabaseHas('states', ['id' => $state->id]);
    }

    public function test_can_delete_a_state_without_dependents(): void
    {
        $state = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);

        Livewire::test(EditState::class, ['record' => $state->id])
            ->callAction('delete');

        $this->assertDatabaseMissing('states', ['id' => $state->id]);
    }
}

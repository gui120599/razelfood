<?php

namespace Tests\Feature\Central;

use App\Enums\LocationSyncStatus;
use App\Filament\Resources\LocationSyncs\Pages\ListLocationSyncs;
use App\Jobs\ProcessLocationSyncChunkJob;
use App\Models\City;
use App\Models\LocationSync;
use App\Models\State;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LocationSyncResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));

        Http::fake([
            'servicodados.ibge.gov.br/*/estados?*' => Http::response([
                ['id' => 52, 'sigla' => 'GO', 'nome' => 'Goiás'],
            ]),
            'servicodados.ibge.gov.br/*/estados/GO/municipios' => Http::response([
                ['id' => 5208707, 'nome' => 'Goiânia'],
            ]),
        ]);
    }

    public function test_super_admin_can_start_a_sync_from_the_form_and_it_dispatches_the_first_job(): void
    {
        Bus::fake();

        Livewire::test(ListLocationSyncs::class)
            ->callAction(TestAction::make('sync'), [
                'uf' => 'GO',
                'city_ibge_code' => 5208707,
                'cep_start' => '74000-001',
                'cep_end' => '74000-010',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('location_syncs', [
            'cep_start' => 74000001,
            'cep_end' => 74000010,
            'status' => LocationSyncStatus::Processing->value,
        ]);
        $this->assertDatabaseHas('cities', ['normalized_name' => 'goiania', 'ibge_code' => 5208707]);
        Bus::assertDispatched(ProcessLocationSyncChunkJob::class);
    }

    public function test_form_blocks_starting_a_second_sync_for_a_city_already_processing(): void
    {
        Bus::fake();

        Livewire::test(ListLocationSyncs::class)
            ->callAction(TestAction::make('sync'), [
                'uf' => 'GO',
                'city_ibge_code' => 5208707,
                'cep_start' => '74000-001',
                'cep_end' => '74000-010',
            ])
            ->assertHasNoActionErrors();

        Livewire::test(ListLocationSyncs::class)
            ->callAction(TestAction::make('sync'), [
                'uf' => 'GO',
                'city_ibge_code' => 5208707,
                'cep_start' => '74001-001',
                'cep_end' => '74001-010',
            ])
            ->assertHasActionErrors(['city_ibge_code']);

        $this->assertSame(1, LocationSync::query()->count());
    }

    public function test_resume_action_is_only_visible_for_paused_or_failed_syncs(): void
    {
        $state = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
        $city = City::create(['state_id' => $state->id, 'name' => 'Goiânia', 'ibge_code' => 5208707]);

        $processing = LocationSync::create([
            'state_id' => $state->id, 'city_id' => $city->id,
            'cep_start' => 1, 'cep_end' => 10, 'total_ceps' => 10,
            'status' => LocationSyncStatus::Processing,
        ]);

        $paused = LocationSync::create([
            'state_id' => $state->id, 'city_id' => $city->id,
            'cep_start' => 11, 'cep_end' => 20, 'total_ceps' => 10,
            'status' => LocationSyncStatus::Paused,
        ]);

        Livewire::test(ListLocationSyncs::class)
            ->assertTableActionVisible('resume', $paused)
            ->assertTableActionHidden('resume', $processing)
            ->assertTableActionVisible('pause', $processing)
            ->assertTableActionHidden('pause', $paused);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\LocationSyncStatus;
use App\Jobs\ProcessLocationSyncChunkJob;
use App\Models\City;
use App\Models\LocationSync;
use App\Models\Neighborhood;
use App\Models\State;
use App\Services\Address\LocationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocationSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private State $state;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = State::create(['name' => 'São Paulo', 'uf' => 'SP', 'ibge_code' => 35]);
        $this->city = City::create(['state_id' => $this->state->id, 'name' => 'São Paulo', 'ibge_code' => 3550308]);
    }

    private function makeSync(int $cepStart, int $cepEnd): LocationSync
    {
        return LocationSync::create([
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'cep_start' => $cepStart,
            'cep_end' => $cepEnd,
            'current_cep' => $cepStart,
            'total_ceps' => $cepEnd - $cepStart + 1,
            'status' => LocationSyncStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function test_process_next_chunk_creates_neighborhood_from_valid_cep_and_skips_not_found(): void
    {
        Http::fake([
            'viacep.com.br/ws/01000000/json/' => Http::response([
                'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
            ]),
            'viacep.com.br/ws/01000001/json/' => Http::response(['erro' => true]),
        ]);

        $sync = $this->makeSync(1000000, 1000001);

        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();

        $this->assertDatabaseHas('neighborhoods', ['city_id' => $this->city->id, 'name' => 'Sé', 'normalized_name' => 'se']);
        $this->assertSame(1, $sync->neighborhoods_created);
        $this->assertSame(1, $sync->neighborhoods_found);
        $this->assertSame(1, $sync->ceps_valid);
        $this->assertSame(1, $sync->ceps_invalid);
        $this->assertSame(2, $sync->ceps_processed);
        $this->assertSame(1000002, $sync->current_cep);
        $this->assertSame(1000001, $sync->last_confirmed_cep);
    }

    public function test_process_next_chunk_is_idempotent_when_run_twice_over_the_same_range(): void
    {
        Http::fake([
            'viacep.com.br/ws/01000000/json/' => Http::response([
                'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
            ]),
        ]);

        $sync = $this->makeSync(1000000, 1000000);

        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();
        $this->assertSame(1, Neighborhood::query()->count());
        $this->assertSame(1, $sync->neighborhoods_created);

        // Simula reprocessamento do mesmo CEP (ex.: retry duplicado do Laravel).
        $sync->update(['current_cep' => 1000000]);
        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();

        $this->assertSame(1, Neighborhood::query()->count());
        $this->assertSame(1, $sync->neighborhoods_created);
    }

    public function test_process_next_chunk_rejects_cep_from_a_different_city_and_logs_it(): void
    {
        Http::fake([
            'viacep.com.br/ws/13000000/json/' => Http::response([
                'bairro' => 'Centro', 'localidade' => 'Campinas', 'uf' => 'SP', 'ibge' => '3509502',
            ]),
        ]);

        $sync = $this->makeSync(13000000, 13000000);

        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();

        $this->assertSame(0, Neighborhood::query()->count());
        $this->assertSame(1, $sync->ceps_invalid);
        $this->assertSame(0, $sync->ceps_valid);
        $this->assertDatabaseHas('location_sync_logs', [
            'location_sync_id' => $sync->id,
            'cep' => 13000000,
            'type' => 'wrong_city',
        ]);
    }

    public function test_process_next_chunk_logs_empty_response_instead_of_wrong_city_when_viacep_returns_blank_address(): void
    {
        // Comum em varreduras grandes: números de CEP não atribuídos a
        // nenhum endereço, onde o ViaCEP responde com sucesso (sem
        // erro:true) mas bairro/cidade/UF vazios — não é "outra cidade".
        Http::fake([
            'viacep.com.br/ws/74999999/json/' => Http::response([
                'logradouro' => '', 'bairro' => '', 'localidade' => '', 'uf' => '',
            ]),
        ]);

        $sync = $this->makeSync(74999999, 74999999);

        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();

        $this->assertSame(0, Neighborhood::query()->count());
        $this->assertSame(1, $sync->ceps_invalid);
        $this->assertDatabaseHas('location_sync_logs', [
            'location_sync_id' => $sync->id,
            'cep' => 74999999,
            'type' => 'empty_response',
        ]);
        $this->assertDatabaseMissing('location_sync_logs', [
            'location_sync_id' => $sync->id,
            'cep' => 74999999,
            'type' => 'wrong_city',
        ]);
    }

    public function test_process_next_chunk_falls_back_to_normalized_name_when_ibge_code_is_missing(): void
    {
        Http::fake([
            'viacep.com.br/ws/01000000/json/' => Http::response([
                'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP',
            ]),
        ]);

        $sync = $this->makeSync(1000000, 1000000);

        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();

        $this->assertSame(1, $sync->ceps_valid);
        $this->assertDatabaseHas('neighborhoods', ['city_id' => $this->city->id, 'normalized_name' => 'se']);
    }

    public function test_process_next_chunk_stops_at_chunk_size_and_advances_checkpoint_cep_by_cep(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response([
                'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
            ]),
        ]);

        $sync = $this->makeSync(1000000, 1000004);

        app(LocationSyncService::class)->processNextChunk($sync, 2);
        $sync->refresh();

        $this->assertSame(2, $sync->ceps_processed);
        $this->assertSame(1000002, $sync->current_cep);
        $this->assertSame(1000001, $sync->last_confirmed_cep);
    }

    public function test_temporary_http_failure_retries_before_advancing_and_is_logged_as_exhausted(): void
    {
        Http::fake([
            'viacep.com.br/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $sync = $this->makeSync(1000000, 1000000);

        app(LocationSyncService::class)->processNextChunk($sync, 10);
        $sync->refresh();

        $this->assertSame(1, $sync->errors_count);
        $this->assertSame(0, $sync->ceps_valid);
        $this->assertSame(0, $sync->ceps_invalid);
        $this->assertSame(1, $sync->ceps_processed);
        $this->assertSame(1000001, $sync->current_cep);
        $this->assertNotNull($sync->last_error);
        $this->assertDatabaseHas('location_sync_logs', [
            'location_sync_id' => $sync->id,
            'cep' => 1000000,
            'type' => 'exhausted_retries',
        ]);
    }

    public function test_start_sync_creates_state_and_city_and_dispatches_the_first_job(): void
    {
        Bus::fake();
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 52, 'sigla' => 'GO', 'nome' => 'Goiás'],
            ]),
        ]);

        $sync = app(LocationSyncService::class)->startSync([
            'uf' => 'GO',
            'city_ibge_code' => 5208707,
            'city_name' => 'Goiânia',
            'cep_start' => 74000001,
            'cep_end' => 74000010,
        ]);

        $this->assertDatabaseHas('states', ['uf' => 'GO', 'ibge_code' => 52]);
        $this->assertDatabaseHas('cities', ['normalized_name' => 'goiania', 'ibge_code' => 5208707]);
        $this->assertSame(LocationSyncStatus::Processing, $sync->status);

        Bus::assertDispatched(ProcessLocationSyncChunkJob::class, fn ($job) => $job->locationSyncId === $sync->id);
    }

    public function test_start_sync_blocks_when_a_pending_or_processing_sync_already_exists_for_the_city(): void
    {
        Bus::fake();
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo'],
            ]),
        ]);

        $this->makeSync(1000000, 1000010);

        $this->expectException(ValidationException::class);

        app(LocationSyncService::class)->startSync([
            'uf' => 'SP',
            'city_ibge_code' => 3550308,
            'city_name' => 'São Paulo',
            'cep_start' => 1000011,
            'cep_end' => 1000020,
        ]);
    }

    public function test_resume_sync_reopens_status_and_redispatches_job_reading_persisted_checkpoint(): void
    {
        Bus::fake();

        $sync = $this->makeSync(1000000, 1000010);
        $sync->update(['status' => LocationSyncStatus::Paused, 'current_cep' => 1000005]);

        app(LocationSyncService::class)->resumeSync($sync);
        $sync->refresh();

        $this->assertSame(LocationSyncStatus::Processing, $sync->status);
        $this->assertSame(1000005, $sync->current_cep);
        Bus::assertDispatched(ProcessLocationSyncChunkJob::class, fn ($job) => $job->locationSyncId === $sync->id);
    }

    public function test_pause_sync_only_flips_status(): void
    {
        $sync = $this->makeSync(1000000, 1000010);
        $sync->update(['current_cep' => 1000003]);

        app(LocationSyncService::class)->pauseSync($sync);
        $sync->refresh();

        $this->assertSame(LocationSyncStatus::Paused, $sync->status);
        $this->assertSame(1000003, $sync->current_cep);
    }
}

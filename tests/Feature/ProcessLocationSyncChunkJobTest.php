<?php

namespace Tests\Feature;

use App\Enums\LocationSyncStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProcessLocationSyncChunkJob;
use App\Models\City;
use App\Models\LocationSync;
use App\Models\State;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LocationSyncCompleted;
use App\Services\Address\LocationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProcessLocationSyncChunkJobTest extends TestCase
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

    private function makeSync(int $cepStart, int $cepEnd, LocationSyncStatus $status = LocationSyncStatus::Processing): LocationSync
    {
        return LocationSync::create([
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'cep_start' => $cepStart,
            'cep_end' => $cepEnd,
            'current_cep' => $cepStart,
            'total_ceps' => $cepEnd - $cepStart + 1,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    public function test_job_redispatches_itself_when_range_is_not_finished(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response(['erro' => true]),
        ]);
        Bus::fake();

        $sync = $this->makeSync(1000000, 1000010);
        config(['services.location_sync.chunk_size' => 2]);

        // handle() é chamado diretamente (não via fila) para poder inspecionar
        // o self::dispatch() que ele mesmo faz para o próximo chunk.
        (new ProcessLocationSyncChunkJob($sync->id))->handle(app(LocationSyncService::class));

        $sync->refresh();
        $this->assertSame(1000002, $sync->current_cep);
        $this->assertSame(LocationSyncStatus::Processing, $sync->status);

        Bus::assertDispatched(ProcessLocationSyncChunkJob::class, fn ($job) => $job->locationSyncId === $sync->id);
    }

    public function test_job_marks_sync_as_completed_and_notifies_super_admins_when_range_finishes(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response(['erro' => true]),
        ]);
        Notification::fake();
        Bus::fake();

        $tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        $admin = User::factory()->create(['tenant_id' => null]);
        $tenantUser = User::factory()->create(['tenant_id' => $tenant->id]);

        $sync = $this->makeSync(1000000, 1000001);
        config(['services.location_sync.chunk_size' => 10]);

        (new ProcessLocationSyncChunkJob($sync->id))->handle(app(LocationSyncService::class));

        $sync->refresh();
        $this->assertSame(LocationSyncStatus::Completed, $sync->status);
        $this->assertNotNull($sync->finished_at);

        Notification::assertSentTo($admin, LocationSyncCompleted::class);
        Notification::assertNotSentTo($tenantUser, LocationSyncCompleted::class);
        Bus::assertNotDispatched(ProcessLocationSyncChunkJob::class);
    }

    public function test_job_does_nothing_when_sync_was_paused_between_dispatch_and_execution(): void
    {
        Http::fake();
        Bus::fake();

        $sync = $this->makeSync(1000000, 1000010, LocationSyncStatus::Paused);

        (new ProcessLocationSyncChunkJob($sync->id))->handle(app(LocationSyncService::class));

        Http::assertNothingSent();
        Bus::assertNotDispatched(ProcessLocationSyncChunkJob::class);

        $sync->refresh();
        $this->assertSame(0, $sync->ceps_processed);
    }

    public function test_job_failed_callback_marks_sync_as_failed(): void
    {
        $sync = $this->makeSync(1000000, 1000010);

        (new ProcessLocationSyncChunkJob($sync->id))->failed(new \Exception('Erro simulado de fila'));

        $sync->refresh();
        $this->assertSame(LocationSyncStatus::Failed, $sync->status);
        $this->assertSame('Erro simulado de fila', $sync->last_error);
    }
}

<?php

namespace App\Jobs;

use App\Enums\LocationSyncStatus;
use App\Models\LocationSync;
use App\Models\User;
use App\Notifications\LocationSyncCompleted;
use App\Services\Address\LocationSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processa um lote (chunk) de CEPs de um App\Models\LocationSync e se
 * auto-redespacha até esgotar a faixa. Nunca recebe chunkStart/chunkEnd
 * explícitos — sempre lê o checkpoint fresco do banco em handle(), o que já
 * resolve retomada automática mesmo num retry do próprio Laravel.
 */
class ProcessLocationSyncChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 180;

    public function __construct(public readonly int $locationSyncId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Evita 2 chunks concorrentes da mesma sincronização (ex.: clique
        // duplo em "Retomar") disputando o mesmo checkpoint. expiresAfter é
        // essencial: sem ele (default 0 = nunca expira) um worker morto à
        // força (kill -9, OOM, deploy) deixa o lock preso pra sempre e a
        // sincronização trava sem nenhum erro visível — expirar um pouco
        // acima do $timeout do Job autocura isso sem precisar de intervenção manual.
        return [
            (new WithoutOverlapping($this->locationSyncId))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(LocationSyncService $service): void
    {
        $sync = LocationSync::findOrFail($this->locationSyncId);

        if ($sync->status !== LocationSyncStatus::Processing) {
            // Foi pausado/cancelado entre o dispatch e a execução deste Job.
            return;
        }

        $service->processNextChunk($sync, (int) config('services.location_sync.chunk_size'));

        $sync->refresh();

        if ($sync->current_cep > $sync->cep_end) {
            $sync->update([
                'status' => LocationSyncStatus::Completed,
                'finished_at' => now(),
            ]);

            User::query()->whereNull('tenant_id')->get()
                ->each(fn (User $admin) => $admin->notify(new LocationSyncCompleted($sync)));

            return;
        }

        self::dispatch($sync->id);
    }

    public function failed(Throwable $e): void
    {
        LocationSync::where('id', $this->locationSyncId)->update([
            'status' => LocationSyncStatus::Failed,
            'last_error' => $e->getMessage(),
        ]);
    }
}

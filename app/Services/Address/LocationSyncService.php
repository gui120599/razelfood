<?php

namespace App\Services\Address;

use App\Enums\LocationSyncStatus;
use App\Jobs\ProcessLocationSyncChunkJob;
use App\Models\City;
use App\Models\LocationSync;
use App\Models\LocationSyncLog;
use App\Models\State;
use App\Support\NeighborhoodNormalizer;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Coordena a sincronização de UF/Cidade/Bairro (RN da conversa de
 * sincronização de localidades): garante UF+Cidade localmente via IBGE,
 * processa a faixa de CEP em lotes via ViaCEP, e mantém um checkpoint
 * persistente em App\Models\LocationSync para permitir pausar/retomar sem
 * nunca reprocessar do zero. O Resource do Filament só chama estes métodos —
 * toda a lógica fica aqui, não no Resource.
 */
class LocationSyncService
{
    public function __construct(
        private readonly IbgeService $ibge,
        private readonly ViaCepClient $viaCep,
    ) {}

    /**
     * Garante (firstOrCreate) a UF e a Cidade localmente a partir dos dados
     * do IBGE — extraído de startSync() para ser reaproveitado também pelo
     * fluxo de importação via RuaCEP (App\Jobs\ImportNeighborhoodsFromRuaCepJob),
     * que precisa da mesma resolução mas não do sweep de CEP.
     */
    public function resolveStateAndCity(string $uf, string $cityName, int $cityIbgeCode): City
    {
        $stateData = collect($this->ibge->states())->firstWhere('uf', $uf);

        $state = State::firstOrCreate(
            ['uf' => $uf],
            [
                'name' => $stateData['name'] ?? $uf,
                'ibge_code' => $stateData['ibge_code'] ?? null,
            ],
        );

        $city = City::firstOrCreate(
            [
                'state_id' => $state->id,
                'normalized_name' => NeighborhoodNormalizer::normalize($cityName),
            ],
            [
                'name' => $cityName,
                'ibge_code' => $cityIbgeCode,
            ],
        );

        if ($city->ibge_code === null) {
            $city->update(['ibge_code' => $cityIbgeCode]);
        }

        return $city;
    }

    /**
     * @param  array{uf: string, city_ibge_code: int, city_name: string, cep_start: int, cep_end: int}  $data
     */
    public function startSync(array $data): LocationSync
    {
        $city = $this->resolveStateAndCity($data['uf'], $data['city_name'], $data['city_ibge_code']);
        $state = $city->state;

        $blocked = LocationSync::query()
            ->where('city_id', $city->id)
            ->whereIn('status', [LocationSyncStatus::Pending, LocationSyncStatus::Processing])
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'city_ibge_code' => 'Já existe uma sincronização em andamento para esta cidade.',
            ]);
        }

        $sync = LocationSync::create([
            'state_id' => $state->id,
            'city_id' => $city->id,
            'cep_start' => $data['cep_start'],
            'cep_end' => $data['cep_end'],
            'current_cep' => $data['cep_start'],
            'total_ceps' => $data['cep_end'] - $data['cep_start'] + 1,
            'status' => LocationSyncStatus::Processing,
            'started_at' => now(),
        ]);

        ProcessLocationSyncChunkJob::dispatch($sync->id);

        return $sync;
    }

    public function resumeSync(LocationSync $sync): void
    {
        $sync->update([
            'status' => LocationSyncStatus::Processing,
            'last_error' => null,
        ]);

        ProcessLocationSyncChunkJob::dispatch($sync->id);
    }

    public function pauseSync(LocationSync $sync): void
    {
        $sync->update(['status' => LocationSyncStatus::Paused]);
    }

    /**
     * Processa até $chunkSize CEPs a partir do checkpoint atual, disparando
     * as consultas ao ViaCEP em paralelo (Http::pool(), com concorrência
     * limitada — não é um loop sequencial nem "milhares simultâneas").
     * Síncrono e puro o bastante para ser testado com Http::fake() sem fila
     * real — NÃO despacha o próximo Job, isso é responsabilidade de quem
     * chama (App\Jobs\ProcessLocationSyncChunkJob).
     *
     * O checkpoint (current_cep/last_confirmed_cep) e os contadores agora
     * avançam por CHUNK inteiro, não mais CEP a CEP (trade-off deliberado
     * pela concorrência: se o Job morrer no meio, o pior caso é reprocessar
     * o chunk inteiro — idempotente via firstOrCreate, só desperdiça algumas
     * chamadas de rede, nunca duplica dado).
     */
    public function processNextChunk(LocationSync $sync, int $chunkSize): void
    {
        $start = $sync->current_cep ?? $sync->cep_start;
        $end = min($start + $chunkSize - 1, $sync->cep_end);

        if ($start > $end) {
            return;
        }

        $ceps = range($start, $end);
        $concurrency = max(1, (int) config('services.location_sync.concurrency'));
        $maxAttempts = max(1, (int) config('services.location_sync.max_attempts_per_cep'));
        $retryBackoffMs = (int) config('services.location_sync.retry_backoff_ms');

        $responses = Http::pool(
            fn (Pool $pool) => collect($ceps)->each(
                fn (int $cep) => $pool->as($cep)
                    ->timeout(3)
                    ->retry($maxAttempts, $retryBackoffMs)
                    ->get($this->viaCep->url(str_pad((string) $cep, 8, '0', STR_PAD_LEFT))),
            ),
            $concurrency,
        );

        $counters = [
            'ceps_processed' => 0,
            'ceps_valid' => 0,
            'ceps_invalid' => 0,
            'neighborhoods_found' => 0,
            'errors_count' => 0,
        ];
        $lastError = null;
        $neighborhoodNames = []; // normalized_name => nome de exibição, só dos CEPs válidos

        foreach ($ceps as $cep) {
            $result = $responses[$cep];
            $counters['ceps_processed']++;

            if ($result instanceof Throwable) {
                // Retries já esgotados (dentro do próprio Http::pool()) —
                // falha definitiva deste CEP. Conta como processado mesmo
                // assim: um CEP ruim não pode travar a fila inteira para sempre.
                LocationSyncLog::create([
                    'location_sync_id' => $sync->id,
                    'cep' => $cep,
                    'type' => 'exhausted_retries',
                    'message' => $result->getMessage(),
                ]);

                $counters['errors_count']++;
                $lastError = $result->getMessage();

                continue;
            }

            $address = $this->viaCep->parseResponse($result);

            if ($address === null) {
                // CEP inexistente — resposta válida e negativa do ViaCEP.
                $counters['ceps_invalid']++;

                continue;
            }

            if (! $this->belongsToSelectedCity($sync, $address)) {
                // Boa parte dos CEPs "errados" numa varredura em massa não são
                // de outra cidade de verdade — são números não atribuídos a
                // nenhum endereço, aos quais o ViaCEP responde com sucesso
                // (sem erro:true) mas bairro/cidade/UF em branco. Separar esse
                // caso evita logar "CEP retornou /, esperado X" sem sentido.
                $isEmptyResponse = blank($address['city']) && blank($address['state']);

                LocationSyncLog::create([
                    'location_sync_id' => $sync->id,
                    'cep' => $cep,
                    'type' => $isEmptyResponse ? 'empty_response' : 'wrong_city',
                    'message' => $isEmptyResponse
                        ? 'CEP existe no ViaCEP mas sem bairro/cidade associados (provável CEP não atribuído a um endereço real).'
                        : "CEP retornou {$address['city']}/{$address['state']}, esperado {$sync->city->name}/{$sync->state->uf}.",
                ]);

                $counters['ceps_invalid']++;

                continue;
            }

            $counters['ceps_valid']++;

            if (filled($address['neighborhood'])) {
                $counters['neighborhoods_found']++;
                $neighborhoodNames[NeighborhoodNormalizer::normalize($address['neighborhood'])] ??= $address['neighborhood'];
            }
        }

        // Um só firstOrCreate por bairro DISTINTO encontrado no chunk (não
        // por CEP) — vários CEPs consecutivos costumam cair no mesmo bairro.
        $neighborhoodsCreated = 0;

        foreach ($neighborhoodNames as $normalizedName => $displayName) {
            $neighborhood = $sync->city->neighborhoods()->firstOrCreate(
                ['normalized_name' => $normalizedName],
                ['name' => $displayName],
            );

            if ($neighborhood->wasRecentlyCreated) {
                $neighborhoodsCreated++;
            }
        }

        $sync->update([
            'current_cep' => $end + 1,
            'last_confirmed_cep' => $end,
            'ceps_processed' => $sync->ceps_processed + $counters['ceps_processed'],
            'ceps_valid' => $sync->ceps_valid + $counters['ceps_valid'],
            'ceps_invalid' => $sync->ceps_invalid + $counters['ceps_invalid'],
            'neighborhoods_found' => $sync->neighborhoods_found + $counters['neighborhoods_found'],
            'neighborhoods_created' => $sync->neighborhoods_created + $neighborhoodsCreated,
            'errors_count' => $sync->errors_count + $counters['errors_count'],
            'last_error' => $lastError ?? $sync->last_error,
        ]);

        // Pausa opcional entre chunks (0 = desligado) — freio adicional pra
        // quem quiser ser mais conservador com a API além do limite de
        // concorrência do Http::pool() acima.
        $delayMs = (int) config('services.location_sync.request_delay_ms');

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * @param  array{street: ?string, neighborhood: ?string, city: ?string, state: ?string, ibge_code: ?string}  $address
     */
    private function belongsToSelectedCity(LocationSync $sync, array $address): bool
    {
        if (filled($address['ibge_code'] ?? null) && filled($sync->city->ibge_code)) {
            return (int) $address['ibge_code'] === (int) $sync->city->ibge_code;
        }

        // Fallback quando o ViaCEP não trouxe o código IBGE nesta resposta.
        return NeighborhoodNormalizer::normalize($address['city']) === $sync->city->normalized_name
            && strtoupper((string) $address['state']) === $sync->state->uf;
    }
}

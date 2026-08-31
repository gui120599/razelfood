<?php

namespace App\Services\Address;

use App\Models\City;
use App\Models\Neighborhood;
use App\Models\State;
use App\Support\NeighborhoodNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta/importa o catálogo global de localidades (states + cities +
 * neighborhoods) entre ambientes. Serve pra reaproveitar em produção uma
 * sincronização (via IBGE/ViaCEP/RuaCEP) já feita localmente, que é lenta.
 *
 * O payload é agrupado por chave NATURAL (UF, código IBGE, nome
 * normalizado), nunca por `id` — os ids autoincrement divergem entre
 * ambientes. A importação faz upsert idempotente: insere o que falta,
 * atualiza a grafia do que mudou, nunca apaga.
 *
 * @phpstan-type NeighborhoodPayload array{name: string}
 * @phpstan-type CityPayload array{name: string, ibge_code: int|null, neighborhoods: list<NeighborhoodPayload>}
 * @phpstan-type StatePayload array{name: string, uf: string, ibge_code: int|null, cities: list<CityPayload>}
 * @phpstan-type CatalogPayload array{version: int, exported_at: string, states: list<StatePayload>}
 */
class LocationCatalogTransfer
{
    private const VERSION = 1;

    /**
     * @return CatalogPayload
     */
    public function export(): array
    {
        $states = State::query()
            ->with(['cities' => fn ($query) => $query->orderBy('name'), 'cities.neighborhoods' => fn ($query) => $query->orderBy('name')])
            ->orderBy('uf')
            ->get()
            ->map(fn (State $state): array => [
                'name' => $state->name,
                'uf' => $state->uf,
                'ibge_code' => $state->ibge_code,
                'cities' => $state->cities->map(fn (City $city): array => [
                    'name' => $city->name,
                    'ibge_code' => $city->ibge_code,
                    'neighborhoods' => $city->neighborhoods
                        ->map(fn (Neighborhood $neighborhood): array => ['name' => $neighborhood->name])
                        ->all(),
                ])->all(),
            ])
            ->all();

        return [
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'states' => $states,
        ];
    }

    public function downloadResponse(): StreamedResponse
    {
        $payload = $this->export();
        $filename = 'localidades-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @param  mixed  $payload  Conteúdo decodificado do arquivo exportado.
     * @return array{states: int, cities: int, neighborhoods: int}
     *
     * @throws ValidationException
     */
    public function import(mixed $payload): array
    {
        $states = $this->validatePayload($payload);

        $counts = ['states' => 0, 'cities' => 0, 'neighborhoods' => 0];

        DB::transaction(function () use ($states, &$counts): void {
            foreach ($states as $stateData) {
                $state = State::updateOrCreate(
                    ['uf' => $stateData['uf']],
                    ['name' => $stateData['name'], 'ibge_code' => $stateData['ibge_code'] ?? null],
                );
                $counts['states']++;

                foreach ($stateData['cities'] ?? [] as $cityData) {
                    $city = City::updateOrCreate(
                        [
                            'state_id' => $state->id,
                            'normalized_name' => NeighborhoodNormalizer::normalize($cityData['name']),
                        ],
                        ['name' => $cityData['name'], 'ibge_code' => $cityData['ibge_code'] ?? null],
                    );
                    $counts['cities']++;

                    $counts['neighborhoods'] += $this->upsertNeighborhoods($city, $cityData['neighborhoods'] ?? []);
                }
            }
        });

        return $counts;
    }

    /**
     * @param  list<array{name: string}>  $neighborhoods
     */
    private function upsertNeighborhoods(City $city, array $neighborhoods): int
    {
        $now = now();

        $rows = collect($neighborhoods)
            ->map(fn (array $neighborhood): ?array => [
                'name' => trim($neighborhood['name']),
                'normalized_name' => NeighborhoodNormalizer::normalize($neighborhood['name']),
            ])
            ->filter(fn (array $row): bool => $row['normalized_name'] !== null)
            ->unique('normalized_name')
            ->map(fn (array $row): array => [
                ...$row,
                'city_id' => $city->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        // `upsert` não dispara o mutator de `name` do model (que preencheria
        // `normalized_name`), por isso a chave normalizada é calculada acima.
        $rows->chunk(500)->each(fn ($chunk) => Neighborhood::upsert(
            $chunk->all(),
            ['city_id', 'normalized_name'],
            ['name', 'updated_at'],
        ));

        return $rows->count();
    }

    /**
     * @return list<StatePayload>
     *
     * @throws ValidationException
     */
    private function validatePayload(mixed $payload): array
    {
        if (! is_array($payload) || ! isset($payload['states']) || ! is_array($payload['states'])) {
            throw ValidationException::withMessages([
                'file' => 'Arquivo inválido: não parece ser um export de localidades.',
            ]);
        }

        if (($payload['version'] ?? null) !== self::VERSION) {
            throw ValidationException::withMessages([
                'file' => 'Versão do arquivo de localidades incompatível com esta instalação.',
            ]);
        }

        foreach ($payload['states'] as $state) {
            if (! is_array($state) || empty($state['uf']) || empty($state['name'])) {
                throw ValidationException::withMessages([
                    'file' => 'Arquivo inválido: há um estado sem UF ou nome.',
                ]);
            }
        }

        return $payload['states'];
    }
}

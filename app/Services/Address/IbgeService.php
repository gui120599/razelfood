<?php

namespace App\Services\Address;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Estado/Município via API oficial do IBGE — usado só pelo Resource de
 * sincronização de localidades (Super Admin), para alimentar os Selects de
 * Estado/Cidade ao vivo. O IBGE não tem catálogo de bairros (ver
 * App\Services\Address\ViaCepClient para isso).
 */
class IbgeService
{
    /**
     * @return array<int, array{ibge_code: int, name: string, uf: string}>
     */
    public function states(): array
    {
        return Cache::remember('ibge:states', now()->addDay(), function () {
            $response = Http::timeout(5)->get(
                config('services.ibge.base_url').'/estados',
                ['orderBy' => 'nome'],
            );

            return collect($response->json())
                ->map(fn (array $state) => [
                    'ibge_code' => $state['id'],
                    'name' => $state['nome'],
                    'uf' => $state['sigla'],
                ])
                ->all();
        });
    }

    /**
     * @return array<int, array{ibge_code: int, name: string}>
     */
    public function citiesOf(string $uf): array
    {
        return Cache::remember("ibge:cities:{$uf}", now()->addDay(), function () use ($uf) {
            $response = Http::timeout(5)->get(
                config('services.ibge.base_url')."/estados/{$uf}/municipios",
            );

            return collect($response->json())
                ->map(fn (array $city) => [
                    'ibge_code' => $city['id'],
                    'name' => $city['nome'],
                ])
                ->all();
        });
    }
}

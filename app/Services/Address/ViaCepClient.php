<?php

namespace App\Services\Address;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * RN-33: busca de endereço por CEP no checkout, via ViaCEP (não a API do
 * IBGE, que não cobre esse caso de uso). É sempre auxiliar — se o CEP for
 * inválido, não for encontrado, ou o serviço externo falhar/expirar, quem
 * chama deve deixar o cliente preencher os campos manualmente, nunca
 * bloquear o checkout por isso.
 */
class ViaCepClient
{
    /**
     * @param  int[]  $retryBackoffMs  Ex.: [500, 2000] — tenta de novo após cada
     *                                 ConnectionException, aguardando os ms indicados
     *                                 entre tentativas. Vazio (default) preserva o
     *                                 comportamento legado: 1 tentativa, falha vira null.
     *                                 Quando informado, uma falha após esgotar as
     *                                 tentativas é RELANÇADA (não vira null) — permite
     *                                 ao chamador (App\Services\Address\LocationSyncService)
     *                                 diferenciar "CEP não existe" de "erro técnico esgotado".
     * @return array{street: ?string, neighborhood: ?string, city: ?string, state: ?string, ibge_code: ?string}|null
     */
    public function lookup(string $cep, array $retryBackoffMs = []): ?array
    {
        $cep = preg_replace('/\D+/', '', $cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        $attempts = count($retryBackoffMs) + 1;
        $response = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = Http::timeout(3)->get($this->url($cep));
                break;
            } catch (ConnectionException $e) {
                if ($attempt < $attempts - 1) {
                    usleep($retryBackoffMs[$attempt] * 1000);

                    continue;
                }

                if ($attempts === 1) {
                    return null;
                }

                throw $e;
            }
        }

        return $this->parseResponse($response);
    }

    /**
     * Monta a URL de consulta para um CEP — exposto para permitir montar
     * requisições em lote (App\Services\Address\LocationSyncService via
     * Http::pool()) sem duplicar essa regra.
     */
    public function url(string $cep): string
    {
        $cep = preg_replace('/\D+/', '', $cep);

        return "https://viacep.com.br/ws/{$cep}/json/";
    }

    /**
     * @return array{street: ?string, neighborhood: ?string, city: ?string, state: ?string, ibge_code: ?string}|null
     */
    public function parseResponse(Response $response): ?array
    {
        if (! $response->ok() || $response->json('erro') === true) {
            return null;
        }

        return [
            'street' => $response->json('logradouro') ?: null,
            'neighborhood' => $response->json('bairro') ?: null,
            'city' => $response->json('localidade') ?: null,
            'state' => $response->json('uf') ?: null,
            'ibge_code' => $response->json('ibge') ?: null,
        ];
    }
}

<?php

namespace App\Services\Fiscal;

use App\Exceptions\NfeIoException;
use App\Models\TenantFiscalSettings;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente HTTP da NFE.io (NFC-e / "consumerinvoices"). Só o que está comprovado
 * no Pizzaria-App: emitir, consultar e obter o DANFE. As credenciais chegam por
 * parâmetro (`TenantFiscalSettings`), então serve tanto à conta da Razel quanto
 * à do tenant. A chave vai no header `Authorization` SEM "Bearer" e NUNCA entra
 * em mensagem de erro, log ou exceção.
 */
class NfeIoClient
{
    private const ERROR_MESSAGE_LIMIT = 300;

    /**
     * Envia a NFC-e. Resposta aceita (2xx) devolve o corpo decodificado.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws NfeIoException
     */
    public function issue(TenantFiscalSettings $settings, array $payload): array
    {
        return $this->request($settings, fn (PendingRequest $http): Response => $http->post($this->invoicesPath($settings), $payload));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NfeIoException
     */
    public function find(TenantFiscalSettings $settings, string $providerId): array
    {
        return $this->request($settings, fn (PendingRequest $http): Response => $http->get($this->invoicesPath($settings).'/'.rawurlencode($providerId)));
    }

    /**
     * URL do DANFE (PDF) da nota.
     *
     * @throws NfeIoException
     */
    public function pdfUrl(TenantFiscalSettings $settings, string $providerId): string
    {
        $body = $this->request($settings, fn (PendingRequest $http): Response => $http->get($this->invoicesPath($settings).'/'.rawurlencode($providerId).'/pdf'));
        $uri = $body['uri'] ?? null;

        if (! is_string($uri) || $uri === '') {
            throw new NfeIoException('A NFE.io não devolveu o endereço do DANFE.');
        }

        return $uri;
    }

    /**
     * Pede o cancelamento da NFC-e (`DELETE`). A resposta só diz se o pedido foi aceito: o
     * resultado final chega por webhook ou consulta.
     *
     * @throws NfeIoException
     */
    public function cancel(TenantFiscalSettings $settings, string $providerId): void
    {
        $this->request($settings, fn (PendingRequest $http): Response => $http->delete($this->invoicesPath($settings).'/'.rawurlencode($providerId)));
    }

    private function invoicesPath(TenantFiscalSettings $settings): string
    {
        return '/companies/'.rawurlencode((string) $settings->nfeio_company_id).'/consumerinvoices';
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws NfeIoException
     */
    private function request(TenantFiscalSettings $settings, Closure $call): array
    {
        try {
            $apiKey = $settings->nfeio_api_key;
        } catch (DecryptException) {
            throw new NfeIoException('Não foi possível ler a chave de API da NFE.io (a chave de criptografia do sistema mudou). Informe a chave novamente em Dados fiscais.');
        }

        if (blank($apiKey) || blank($settings->nfeio_company_id)) {
            throw new NfeIoException('As credenciais da NFE.io não estão preenchidas.');
        }

        $http = Http::baseUrl((string) config('services.nfeio.base_url'))
            ->withHeaders(['Authorization' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout((int) config('services.nfeio.timeout'));

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new NfeIoException('Não foi possível conectar à NFE.io (tempo esgotado ou sem conexão). Não se sabe se a nota foi recebida: consulte o status antes de reenviar.');
        }

        if ($response->failed()) {
            throw new NfeIoException($this->failureMessage($response), $response->status());
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * Mensagem segura (sem request, headers ou chave): status HTTP + o texto de erro devolvido, truncado.
     */
    private function failureMessage(Response $response): string
    {
        $status = $response->status();
        $body = $response->json();
        $detail = is_array($body) ? ($body['message'] ?? $body['error'] ?? null) : null;

        $message = match (true) {
            $status === 401 || $status === 403 => 'A NFE.io recusou as credenciais (HTTP '.$status.'). Confira a chave de API e o ID da empresa.',
            $status === 404 => 'A NFE.io não encontrou o recurso (HTTP 404). Confira o ID da empresa.',
            $status >= 500 => 'A NFE.io está indisponível no momento (HTTP '.$status.').',
            default => 'A NFE.io recusou a requisição (HTTP '.$status.').',
        };

        if (is_string($detail) && $detail !== '' && $status !== 401 && $status !== 403) {
            $message .= ' '.Str::limit(trim($detail), self::ERROR_MESSAGE_LIMIT);
        }

        return $message;
    }
}

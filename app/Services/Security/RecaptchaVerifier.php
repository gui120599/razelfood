<?php

namespace App\Services\Security;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verificação server-side do token do Google reCAPTCHA (RN-29). Serviço
 * fino, sem pacote — mesma disciplina do App\Services\Address\ViaCepClient
 * (timeout curto, captura só de ConnectionException).
 *
 * Política de falha: só bloqueia quando o Google devolve um "não" explícito
 * (`success: false` ou token vazio). Se a chamada HTTP em si falha (Google
 * fora do ar, timeout), libera com um Log::warning — um blip do Google não
 * pode zerar os pedidos de um restaurante inteiro.
 */
class RecaptchaVerifier
{
    private const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    public function verify(?string $token, string $secretKey): bool
    {
        if (blank($token) || blank($secretKey)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(4)->post(self::ENDPOINT, [
                'secret' => $secretKey,
                'response' => $token,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('reCAPTCHA: falha de conexão ao verificar token, checkout liberado.', ['message' => $e->getMessage()]);

            return true;
        }

        if (! $response->ok()) {
            Log::warning('reCAPTCHA: resposta HTTP inesperada ao verificar token, checkout liberado.', ['status' => $response->status()]);

            return true;
        }

        return $response->json('success') === true;
    }
}

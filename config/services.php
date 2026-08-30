<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ibge' => [
        'base_url' => env('IBGE_BASE_URL', 'https://servicodados.ibge.gov.br/api/v1/localidades'),
    ],

    'location_sync' => [
        // Quantos CEPs um Job processa por execução antes de se
        // auto-redespachar para o próximo chunk.
        'chunk_size' => (int) env('LOCATION_SYNC_CHUNK_SIZE', 60),
        // Quantas consultas ao ViaCEP ficam em voo simultaneamente dentro de
        // um chunk (Http::pool()) — é isso que controla o throughput.
        // Subir demais é mais agressivo com a API pública/gratuita.
        'concurrency' => (int) env('LOCATION_SYNC_CONCURRENCY', 15),
        // Pausa opcional aplicada 1x ao final de cada chunk (não mais por
        // CEP) — freio extra além da concorrência. 0 desliga.
        'request_delay_ms' => (int) env('LOCATION_SYNC_REQUEST_DELAY_MS', 0),
        'max_attempts_per_cep' => (int) env('LOCATION_SYNC_MAX_ATTEMPTS_PER_CEP', 3),
        'retry_backoff_ms' => (int) env('LOCATION_SYNC_RETRY_BACKOFF_MS', 500),
    ],

];

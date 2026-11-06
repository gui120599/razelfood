<?php

use App\Support\Webhooks\NfeIoWebhookTenantResolver;

return [

    /*
     * Webhooks externos (NFE.io, Stone...) chegam em
     * `razelfood.com.br/webhooks/{provedor}/{token}`, fora do grupo {tenant},
     * sem sessão/CSRF (routes/webhooks.php). O rate limit usa o limiter
     * nomeado "webhooks" (AppServiceProvider): um teto por IP + token (cobre o
     * provedor legítimo) e um teto por IP (cobre varredura de tokens).
     * (int) obrigatório: valor vindo do .env é string (.ai/rules/config.md).
     */
    'rate_limit_per_minute' => (int) env('WEBHOOKS_RATE_LIMIT_PER_MINUTE', 120),

    'rate_limit_per_ip_per_minute' => (int) env('WEBHOOKS_RATE_LIMIT_PER_IP_PER_MINUTE', 600),

    /*
     * Registro de resolvers de tenant por provedor: nome usado no middleware
     * `webhook.tenant:{provedor}` => classe que implementa
     * App\Support\Webhooks\WebhookTenantResolver (token opaco → Tenant).
     * A Fase 1.3 registra 'nfeio'; a 2.3 registrará 'stone'.
     */
    'providers' => [
        'nfeio' => NfeIoWebhookTenantResolver::class,
    ],

];

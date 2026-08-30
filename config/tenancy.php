<?php

return [

    /*
     * Domínio base usado para resolver o tenant a partir do subdomínio
     * da requisição ({slug}.razelfood.com.br).
     */
    'base_domain' => env('TENANCY_BASE_DOMAIN', 'razelfood.com.br'),

    /*
     * Cache de resolução de tenant por slug (IdentifyTenant). Desativar em
     * desenvolvimento evita ficar com o tenant "preso" no status antigo
     * após suspender/reativar manualmente para teste — o cache fica na
     * tabela `cache` (CACHE_STORE=database) e sobrevive a restart do Docker.
     * Reativar ao subir para hospedagem (produção).
     */
    'cache' => [
        'enabled' => env('TENANCY_CACHE_ENABLED', true),
        'ttl_minutes' => env('TENANCY_CACHE_TTL_MINUTES', 5),
    ],

    /*
     * Slugs que nunca podem ser usados por um tenant, para não colidir
     * com rotas do próprio sistema (RN-04). Expandir conforme necessário.
     */
    'reserved_slugs' => [
        'www', 'app', 'api', 'admin', 'painel', 'interno', 'suporte',
        'blog', 'mail', 'ftp', 'cdn', 'static', 'assets', 'docs', 'help',
        'status', 'dev', 'staging', 'homolog', 'teste', 'demo',
        'minhaconta', 'cardapio', 'pedido', 'pedidos', 'central',
    ],

];

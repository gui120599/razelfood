<?php

return [

    /*
     * Domínio único da aplicação. O tenant NÃO é mais resolvido a partir do
     * subdomínio — é o primeiro segmento do path (`razelfood.com.br/{slug}/`)
     * nas rotas públicas, e o parâmetro nativo de tenancy do Filament
     * (`razelfood.com.br/painel/{slug}`) no painel. Este valor é usado só
     * para montar URLs absolutas / e-mail e como fallback de host.
     */
    'base_domain' => env('TENANCY_BASE_DOMAIN', 'razelfood.com.br'),

    /*
     * Cache de resolução de tenant por slug (ResolveTenantFromPath). Desativar
     * em desenvolvimento evita ficar com o tenant "preso" no status antigo
     * após suspender/reativar manualmente para teste — o cache fica na
     * tabela `cache` (CACHE_STORE=database) e sobrevive a restart do Docker.
     * Reativar ao subir para hospedagem (produção).
     */
    'cache' => [
        'enabled' => (bool) env('TENANCY_CACHE_ENABLED', true),
        // (int) obrigatório: vindo do .env o valor é string ('5') e o Carbon 3
        // rejeita string em now()->addMinutes() (ResolveTenantFromPath),
        // quebrando toda requisição de cardápio de tenant.
        'ttl_minutes' => (int) env('TENANCY_CACHE_TTL_MINUTES', 5),
    ],

    /*
     * Primeiros segmentos de path que NUNCA podem ser um slug de tenant
     * (RN-04). Com a resolução por path, esta lista é crítica: um slug igual
     * a qualquer um destes colidiria com uma rota do próprio sistema
     * (painéis Filament, Livewire, health-check, assets). É usada em três
     * lugares: a constraint `->where('tenant', ...)` do grupo /{tenant} em
     * routes/web.php, a checagem defensiva em ResolveTenantFromPath, e a
     * validação de App\Rules\ValidTenantSlug. Expandir conforme necessário.
     */
    'reserved_slugs' => [
        // rotas / painéis do sistema
        'admin', 'painel', 'central', 'interno', 'login', 'logout',
        'livewire', 'filament', 'sanctum', 'storage', 'up', 'build', 'vendor',
        'assets', 'static', 'css', 'js', 'images', 'img', 'fonts',
        'favicon.ico', 'robots.txt',
        // segmentos das próprias rotas públicas do tenant
        'checkout', 'acompanhar', 'comanda', 'entrega', 'relatorios',
        // genéricos / marketing / reservados históricos
        'www', 'app', 'api', 'suporte', 'blog', 'mail', 'ftp', 'cdn', 'docs',
        'help', 'status', 'dev', 'staging', 'homolog', 'teste', 'demo',
        'minhaconta', 'cardapio', 'pedido', 'pedidos',
    ],

];

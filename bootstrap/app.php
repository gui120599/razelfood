<?php

use App\Http\Middleware\ResolveTenantFromWebhookToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Webhooks externos: prefixo /webhooks fora do grupo {tenant} e FORA do
        // grupo `web` (sem sessão/CSRF), com rate limit nomeado "webhooks"
        // (AppServiceProvider). 'webhooks' é slug reservado (config/tenancy.php).
        then: function (): void {
            Route::middleware('throttle:webhooks')
                ->prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // O tenant é resolvido por rota, não globalmente:
        // - rotas públicas do cardápio: App\Http\Middleware\ResolveTenantFromPath
        //   (middleware do grupo Route::prefix('{tenant}') em routes/web.php);
        // - painel do tenant: mecanismo nativo do Filament (->tenant()) +
        //   App\Http\Middleware\ApplyTenantScopes (tenant middleware persistente);
        // - webhooks externos: token opaco na URL via o alias abaixo
        //   (`webhook.tenant:{provedor}`, routes/webhooks.php).
        $middleware->alias([
            'webhook.tenant' => ResolveTenantFromWebhookToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

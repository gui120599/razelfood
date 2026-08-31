<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // O tenant é resolvido por rota, não globalmente:
        // - rotas públicas do cardápio: App\Http\Middleware\ResolveTenantFromPath
        //   (middleware do grupo Route::prefix('{tenant}') em routes/web.php);
        // - painel do tenant: mecanismo nativo do Filament (->tenant()) +
        //   App\Http\Middleware\ApplyTenantScopes (tenant middleware persistente).
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

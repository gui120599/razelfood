<?php

use App\Http\Controllers\Webhooks\NfeIoWebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks externos por tenant. Carregado por bootstrap/app.php (`then:`) sob
// o prefixo `/webhooks` e o middleware `throttle:webhooks`, SEM o grupo `web`
// (sem sessão nem CSRF — o provedor não tem cookie). 'webhooks' está em
// config('tenancy.reserved_slugs'), então o grupo {tenant} de routes/web.php
// nunca captura este prefixo.
//
// Cada rota usa `webhook.tenant:{provedor}` (resolve o tenant pelo token da
// URL) e um controller que estende TenantWebhookController.

// NFE.io (Fase 1.3): status e cancelamento da NFC-e.
Route::post('/nfeio/{token}', NfeIoWebhookController::class)
    ->middleware('webhook.tenant:nfeio')
    ->name('webhooks.nfeio');

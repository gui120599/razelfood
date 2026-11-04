<?php

// Webhooks externos por tenant. Carregado por bootstrap/app.php (`then:`) sob
// o prefixo `/webhooks` e o middleware `throttle:webhooks`, SEM o grupo `web`
// (sem sessão nem CSRF — o provedor não tem cookie). 'webhooks' está em
// config('tenancy.reserved_slugs'), então o grupo {tenant} de routes/web.php
// nunca captura este prefixo.
//
// Cada rota usa `webhook.tenant:{provedor}` (resolve o tenant pelo token da
// URL) e um controller que estende TenantWebhookController, p.ex. (Fase 1.3):
//
//   Route::post('/nfeio/{token}', NfeIoWebhookController::class)
//       ->middleware('webhook.tenant:nfeio')
//       ->name('webhooks.nfeio');
//
// Fase 0: só a infraestrutura; nenhuma rota real ainda.

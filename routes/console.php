<?php

use App\Models\FiscalWebhook;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset do pool diário das promoções relâmpago recorrentes. A cada 5 min
// para ser compatível com o cron de hospedagem compartilhada (docs/
// modelagem-middleware-multitenant.md §9) — o próprio checkout também
// reseta como rede de segurança.
Schedule::command('promotions:reset-recurring')->everyFiveMinutes()->withoutOverlapping();

// Acerta a data de ativação do PDV por tenant (rede de segurança dos observers).
Schedule::command('sales:sync-pdv-activation')->everyFiveMinutes()->withoutOverlapping();

// Rascunhos de venda vazios parados (o descarte ao sair da tela pode falhar). Tenant-aware.
Schedule::command('sales:discard-empty-drafts')->everyFifteenMinutes()->withoutOverlapping(30);

// Reconcilia com a NFE.io as NFC-e que ficaram paradas (webhook perdido). A cada 10 min,
// compatível com o cron de 1 min da hospedagem compartilhada; só tenants com a feature `nfe_emissao`.
Schedule::command('fiscal:sync-status')->everyTenMinutes()->withoutOverlapping(30);

// Retenção da auditoria de webhooks da NFE.io (90 dias).
Schedule::command('model:prune', ['--model' => [FiscalWebhook::class]])->daily();

// Fila `database` sem worker permanente (hospedagem compartilhada): a cada minuto processa o que
// houver e sai (`--stop-when-empty`, no máximo 55 s), como o agendador do Pizzaria-App. Atende os
// jobs que vão para a fila (reconciliações, sincronização de localidades, cancelamentos de vendas
// sem pagamento). A NFC-e automática e a conclusão do cancelamento de venda NÃO passam por aqui:
// usam `dispatchAfterResponse`.
Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->withoutOverlapping(10);

<?php

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

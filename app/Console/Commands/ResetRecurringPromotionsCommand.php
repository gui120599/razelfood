<?php

namespace App\Console\Commands;

use App\Models\FlashPromotion;
use Illuminate\Console\Command;

/**
 * Zera o pool diário (`sold_quantity`) das promoções relâmpago recorrentes
 * cujo último reset não foi hoje. Roda pelo scheduler (routes/console.php) —
 * sem ele, uma promoção esgotada só voltava a aparecer no cardápio quando
 * alguém tentava comprar, e o contador de escassez ficava defasado.
 *
 * Varre todos os tenants: FlashPromotion tem TenantScope, então é preciso
 * `withoutGlobalScopes()` — o comando roda fora de um contexto de tenant.
 */
class ResetRecurringPromotionsCommand extends Command
{
    protected $signature = 'promotions:reset-recurring';

    protected $description = 'Zera o pool diário das promoções relâmpago recorrentes que ainda não foram resetadas hoje.';

    public function handle(): int
    {
        $reset = 0;

        FlashPromotion::withoutGlobalScopes()
            ->where('is_recurring', true)
            ->whereNotNull('total_quantity')
            ->where(function ($query) {
                $query->whereNull('last_reset_at')
                    ->orWhereDate('last_reset_at', '<', now()->toDateString());
            })
            ->chunkById(200, function ($promotions) use (&$reset) {
                foreach ($promotions as $promotion) {
                    if ($promotion->needsRecurringReset()) {
                        $promotion->update(['sold_quantity' => 0, 'last_reset_at' => now()]);
                        $reset++;
                    }
                }
            });

        $this->components->info("Promoções recorrentes resetadas: {$reset}.");

        return self::SUCCESS;
    }
}

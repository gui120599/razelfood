<?php

namespace App\Console\Commands;

use App\Actions\Sales\DiscardEmptySale;
use App\Console\Concerns\RunsForEachTenant;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Support\FeatureKey;
use Illuminate\Console\Command;

/**
 * Rede de segurança do descarte ao sair da tela (sendBeacon pode falhar): apaga os rascunhos de
 * venda VAZIOS parados há mais de 4 horas, por tenant com o PDV ligado. Rascunho com conteúdo
 * nunca é apagado aqui (o operador pode retomar ou cancelar com motivo).
 */
class DiscardEmptySalesCommand extends Command
{
    use RunsForEachTenant;

    private const STALE_HOURS = 4;

    protected $signature = 'sales:discard-empty-drafts';

    protected $description = 'Descarta rascunhos de venda vazios parados há mais de 4 horas.';

    public function handle(DiscardEmptySale $discard): int
    {
        $discarded = 0;

        $result = $this->forEachTenantWithFeature(FeatureKey::PDV, function () use ($discard, &$discarded): void {
            Sale::query()->where('status', SaleStatus::Open)
                ->where('updated_at', '<', now()->subHours(self::STALE_HOURS))
                ->orderBy('id')
                ->pluck('id')
                ->each(function (int $id) use ($discard, &$discarded): void {
                    $discarded += $discard($id) ? 1 : 0;
                });
        });

        $this->components->info("Rascunhos descartados: {$discarded}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

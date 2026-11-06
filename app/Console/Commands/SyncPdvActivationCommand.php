<?php

namespace App\Console\Commands;

use App\Actions\Sales\SyncPdvActivation;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de segurança de SyncPdvActivation: percorre todos os tenants e acerta a
 * data de corte do PDV, pegando mudanças que os observers não veem (ex.: editar
 * as features de um plano). Falha em um tenant é logada com `tenant_id` e não
 * interrompe os demais. Roda no scheduler (routes/console.php).
 */
class SyncPdvActivationCommand extends Command
{
    protected $signature = 'sales:sync-pdv-activation';

    protected $description = 'Acerta a data de ativação do PDV de cada tenant e cancela vendas sem pagamento de quem perdeu a feature.';

    public function handle(SyncPdvActivation $sync): int
    {
        $failed = 0;

        Tenant::query()->chunkById(100, function ($tenants) use ($sync, &$failed): void {
            foreach ($tenants as $tenant) {
                try {
                    $sync($tenant);
                } catch (Throwable $exception) {
                    $failed++;

                    Log::error("Falha ao sincronizar a ativação do PDV do tenant #{$tenant->id}.", [
                        'tenant_id' => $tenant->id,
                        'exception' => $exception,
                    ]);
                }
            }
        });

        if ($failed > 0) {
            $this->components->error("Tenants com falha: {$failed}.");

            return self::FAILURE;
        }

        $this->components->info('Ativação do PDV sincronizada.');

        return self::SUCCESS;
    }
}

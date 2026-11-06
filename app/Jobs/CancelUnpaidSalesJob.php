<?php

namespace App\Jobs;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\DiscardEmptySale;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\FeatureKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Quando um tenant perde a feature `pdv`, cancela as vendas Finalizadas que
 * NÃO têm pagamento registrado (as pagas ficam como estão — mexer em dinheiro
 * recebido é decisão de uma pessoa). Roda no contexto do tenant (MT-01) e
 * cancela pelo mesmo CancelSale, então respeita o gancho fiscal: uma venda com
 * documento fiscal vivo é pulada e logada, não cancelada.
 *
 * Como a fila pode demorar (cron na hospedagem compartilhada), o job REVALIDA
 * ao rodar: se o PDV voltou entre o despacho e a execução, não faz nada — vendas
 * do período novo nunca são canceladas por um job do período antigo. É único por
 * tenant (despachos repetidos não empilham) e falha de uma venda não impede as
 * demais: o erro é relançado só no fim, para a fila tentar de novo.
 */
class CancelUnpaidSalesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $tenantId) {}

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(CancelSale $cancelSale): void
    {
        $tenant = Tenant::query()->with(['plan.features', 'featureOverrides.feature'])->find($this->tenantId);

        if ($tenant === null || $tenant->hasFeature(FeatureKey::PDV)) {
            return;
        }

        $failure = null;

        // Rascunhos abertos (D-08) também caem: vazios são descartados, os demais cancelados
        // (liberam os itens reservados). Vendas finalizadas só se não tiverem pagamento.
        $sales = Sale::query()
            ->where(fn ($query) => $query->where('status', SaleStatus::Open)
                ->orWhere(fn ($finalized) => $finalized->where('status', SaleStatus::Finalized)->whereDoesntHave('payments')))
            ->orderBy('id')
            ->get();

        foreach ($sales as $sale) {
            try {
                if ($sale->status === SaleStatus::Open && app(DiscardEmptySale::class)($sale)) {
                    continue;
                }

                $cancelSale($sale, 'Cancelada automaticamente: o PDV foi desativado para o estabelecimento.');
            } catch (SaleException $exception) {
                Log::warning("Venda {$sale->displayNumber()} não foi cancelada ao desativar o PDV: {$exception->getMessage()}", [
                    'tenant_id' => $this->tenantId,
                    'sale_id' => $sale->id,
                ]);
            } catch (Throwable $exception) {
                Log::error("Falha ao cancelar a venda {$sale->displayNumber()} ao desativar o PDV.", [
                    'tenant_id' => $this->tenantId,
                    'sale_id' => $sale->id,
                    'exception' => $exception,
                ]);

                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}

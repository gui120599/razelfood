<?php

namespace App\Jobs;

use App\Actions\Sales\CancelSale;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conclui o cancelamento de uma venda que estava aguardando a NFE.io cancelar a NFC-e
 * (ação combinada). Roda no contexto do tenant, é único por venda e REVALIDA ao executar:
 * só cancela se a venda ainda está finalizada, com cancelamento pendente e sem nota viva.
 * Uma recusa de regra solta o pendente e registra o motivo; erro inesperado tenta de novo.
 */
class CompleteSaleCancellationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $tenantId, public readonly int $saleId) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->saleId}";
    }

    public function handle(CancelSale $cancelSale): void
    {
        $sale = Sale::query()->find($this->saleId);

        if ($sale === null || $sale->status !== SaleStatus::Finalized || ! $sale->hasPendingCancellation()) {
            return;
        }

        try {
            $cancelSale($sale, (string) $sale->pending_cancellation_note, $sale->pending_cancellation_by_user_id ? User::query()->find($sale->pending_cancellation_by_user_id) : null);
        } catch (SaleException $exception) {
            $sale->update(['pending_cancellation_note' => null, 'pending_cancellation_by_user_id' => null, 'pending_cancellation_at' => null]);

            Log::warning("Venda {$sale->displayNumber()} não foi cancelada após o cancelamento da NFC-e: {$exception->getMessage()}", [
                'tenant_id' => $this->tenantId,
                'sale_id' => $this->saleId,
            ]);
        }
    }

    /**
     * Esgotou as tentativas: solta o pendente para o operador poder agir (a nota já está cancelada,
     * então "Cancelar venda" volta a funcionar) e registra a falha.
     */
    public function failed(Throwable $exception): void
    {
        $this->inTenantContext(function () use ($exception): void {
            Sale::query()->whereKey($this->saleId)->update(['pending_cancellation_note' => null, 'pending_cancellation_by_user_id' => null, 'pending_cancellation_at' => null]);

            Log::error('Falha ao concluir o cancelamento da venda após o cancelamento da NFC-e.', [
                'tenant_id' => $this->tenantId,
                'sale_id' => $this->saleId,
                'exception' => $exception::class,
            ]);
        });
    }
}

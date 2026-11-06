<?php

namespace App\Jobs;

use App\Actions\Fiscal\IssueFiscalDocument;
use App\Enums\FiscalDocumentDecision;
use App\Exceptions\FiscalDocumentException;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emissão automática da NFC-e após o fechamento da venda (RN-FIS-12). Roda no contexto
 * do tenant (MT-01) e NÃO tenta de novo (`tries = 1`): se o POST foi aceito mas a
 * resposta se perdeu, uma segunda tentativa poderia duplicar a nota. O caminho de
 * recuperação é "Reenviar" / "Atualizar status" na tela da venda. Uma recusa de regra
 * (pendência fiscal, feature desligada) fica gravada no documento/log, sem falhar o job.
 */
class IssueFiscalDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public int $tries = 1;

    public function __construct(public readonly int $tenantId, public readonly int $saleId) {}

    public function handle(IssueFiscalDocument $issue): void
    {
        $sale = Sale::query()->find($this->saleId);

        if ($sale === null) {
            return;
        }

        try {
            $issue($sale, FiscalDocumentDecision::Automatic);
        } catch (FiscalDocumentException $exception) {
            Log::warning("NFC-e automática da venda {$sale->displayNumber()} não foi emitida: {$exception->getMessage()}", [
                'tenant_id' => $this->tenantId,
                'sale_id' => $this->saleId,
            ]);
        } catch (Throwable $exception) {
            // Despachado com `dispatchAfterResponse` (driver sync, dentro do `terminate()` do app): uma
            // exceção relançada abortaria os demais callbacks de terminação. Sem retentativa (tries = 1),
            // então só reporta; a nota sai por "Reenviar" / "Atualizar status".
            report($exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->inTenantContext(function () use ($exception): void {
            Log::error('Falha inesperada ao emitir a NFC-e automática.', [
                'tenant_id' => $this->tenantId,
                'sale_id' => $this->saleId,
                'exception' => $exception::class,
            ]);
        });
    }
}

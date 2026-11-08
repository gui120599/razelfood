<?php

namespace App\Actions\Sales\Concerns;

use App\Actions\Fiscal\ShouldAutoIssue;
use App\Jobs\IssueFiscalDocumentJob;
use App\Models\Sale;
use App\Models\Tenant;
use Throwable;

/**
 * Emissão automática da NFC-e (RN-FIS-12) depois do commit da venda e fora da requisição
 * (`dispatchAfterResponse`, sem worker). A venda já foi efetivada: uma falha ao decidir/enfileirar
 * a nota não pode virar erro de fechamento — a nota sai pelo botão. O job revalida tudo ao rodar.
 * Exige `$this->shouldAutoIssue` (ShouldAutoIssue) na classe.
 */
trait DispatchesAutoIssue
{
    protected function dispatchAutoIssue(Tenant $tenant, Sale $sale): void
    {
        try {
            if (($this->shouldAutoIssue)($tenant, $sale)) {
                IssueFiscalDocumentJob::dispatchAfterResponse($tenant->id, $sale->id);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

namespace App\Http\Controllers\Reports;

use App\Support\CurrentTenant;
use App\Support\Fiscal\FiscalReportQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Versão imprimível (A4) do Relatório fiscal, aberta em nova aba a partir da página.
 */
class FiscalReportPrintController extends FiscalReportExportController
{
    public function __invoke(Request $request): View
    {
        [$report, $dimensions] = $this->build($request);

        return view('reports.fiscal-print', [
            'tenant' => CurrentTenant::get(),
            'periodLabel' => 'Autorizadas de '.$report->start()->format('d/m/Y').' a '.$report->end()->format('d/m/Y'),
            'summary' => $report->summary(),
            'groups' => collect($dimensions)->mapWithKeys(fn (string $dimension): array => [$dimension => $report->group($dimension)]),
            'dimensions' => FiscalReportQuery::DIMENSIONS,
            'titles' => FiscalReportQuery::TITLES,
            'notes' => $report->notes(),
            'notesTotals' => $report->notesTotals(),
            'withoutItems' => $report->notesWithoutItems(),
            'unused' => $report->unusedNumbers(),
        ]);
    }
}

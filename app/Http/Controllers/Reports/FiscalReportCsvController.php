<?php

namespace App\Http\Controllers\Reports;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV do Relatório fiscal (UTF-8 com BOM e ";" para abrir direto no Excel em pt-BR, como o do Pizzaria-App).
 */
class FiscalReportCsvController extends FiscalReportExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        [$report, $dimensions] = $this->build($request);
        $lines = $report->toCsv($dimensions);

        return response()->streamDownload(function () use ($lines): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            foreach ($lines as $line) {
                fputcsv($output, array_map(self::safeCell(...), $line), ';', '"', '');
            }

            fclose($output);
        }, 'relatorio-fiscal-nfce-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutraliza injeção de fórmula: texto que começa com = + - @ (ou tab/CR) ganha um apóstrofo
     * para o Excel não executá-lo. Nomes de categoria/forma de pagamento são livres.
     */
    private static function safeCell(mixed $cell): mixed
    {
        return is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }
}

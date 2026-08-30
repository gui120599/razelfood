<?php

namespace App\Actions\Reports;

use App\Support\Orders\DurationFormatter;
use App\Support\Reports\DeliveriesReport;
use Carbon\CarbonInterface;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportação CSV do relatório de entregas por entregador (RF-31). Uma linha
 * por pedido entregue, ordenada por entregador. `league/csv` direto, sem a
 * infra de job batching do Filament.
 */
class ExportDeliveriesCsv
{
    private const HEADERS = [
        'Entregador', 'Pedido', 'Entregue', 'Cliente', 'Bairro', 'Endereco',
        'Pagamento', 'Taxa', 'Total', 'Tempo',
    ];

    public function __construct(private readonly DeliveriesReport $report) {}

    public function response(CarbonInterface $start, CarbonInterface $end): StreamedResponse
    {
        $filename = 'relatorio-entregas-'.$start->toDateString().'-a-'.$end->toDateString().'.csv';

        return response()->streamDownload(
            fn () => print ($this->contents($start, $end)),
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    public function contents(CarbonInterface $start, CarbonInterface $end): string
    {
        $csv = Writer::fromString('');
        $csv->insertOne(self::HEADERS);

        foreach ($this->report->groups($start, $end) as $group) {
            foreach ($group['orders'] as $order) {
                $csv->insertOne([
                    $group['name'],
                    (string) $order['number'],
                    $order['delivered_at']?->format('d/m/Y H:i'),
                    $order['client'],
                    $order['neighborhood'],
                    $order['address'],
                    $order['payments'],
                    number_format($order['delivery_fee'], 2, ',', '.'),
                    number_format($order['total'], 2, ',', '.'),
                    $order['minutes'] === null ? '' : DurationFormatter::minutes($order['minutes']),
                ]);
            }
        }

        return $csv->toString();
    }
}

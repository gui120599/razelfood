<?php

namespace App\Actions\Reports;

use App\Models\Order;
use Carbon\CarbonInterface;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportação CSV dos pedidos de um período (RF-31). Stream direto com
 * `league/csv` (já disponível via `filament/actions`) — sem a infra de
 * export com job batching do Filament. Escopo de tenant automático via
 * TenantScope de Order.
 */
class ExportOrdersCsv
{
    private const HEADERS = [
        'Numero', 'ID', 'Data/Hora', 'Status', 'Origem', 'Cliente', 'Telefone',
        'Modalidade', 'Bairro', 'Subtotal', 'Desconto', 'Entrega', 'Total',
        'Formas de pagamento', 'Motivo cancelamento',
    ];

    public function response(CarbonInterface $start, CarbonInterface $end): StreamedResponse
    {
        $filename = 'relatorio-pedidos-'.$start->toDateString().'-a-'.$end->toDateString().'.csv';

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

        Order::query()
            ->with(['client', 'payments', 'deliveryOption'])
            ->openedBetween($start, $end)
            ->orderBy('id')
            ->lazy()
            ->each(fn (Order $order) => $csv->insertOne($this->row($order)));

        return $csv->toString();
    }

    /**
     * @return array<int, string|null>
     */
    private function row(Order $order): array
    {
        return [
            (string) $order->order_number,
            (string) $order->id,
            $order->opened_at?->format('d/m/Y H:i'),
            $order->status->label(),
            $order->origin->label(),
            $order->client?->name,
            $order->client?->phone,
            $order->deliveryOption?->name ?? 'Retirada no local',
            $order->delivery_neighborhood,
            number_format((float) $order->items_total, 2, ',', '.'),
            number_format((float) $order->discount_total, 2, ',', '.'),
            number_format((float) $order->delivery_fee, 2, ',', '.'),
            number_format((float) $order->grand_total, 2, ',', '.'),
            $order->payments->pluck('payment_option_name')->implode(' + '),
            $order->cancellation_reason?->label(),
        ];
    }
}

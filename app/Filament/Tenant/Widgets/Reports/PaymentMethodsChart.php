<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\OrderStatus;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Support\BrandColors;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;

class PaymentMethodsChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Formas de pagamento (por valor)';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $orderIds = $this->ordersInPeriod()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->toBase()
            ->pluck('id');

        $rows = DB::table('order_payments')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('payment_option_name, SUM(amount) as total, COUNT(*) as qty')
            ->groupBy('payment_option_name')
            ->orderByDesc('total')
            ->get();

        return [
            'datasets' => [[
                'label' => 'Valor recebido (R$)',
                'data' => $rows->map(fn ($row) => round((float) $row->total, 2))->all(),
                'backgroundColor' => BrandColors::TEAL_500,
            ]],
            'labels' => $rows->map(fn ($row) => $row->payment_option_name.' ('.$row->qty.')')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ];
    }
}

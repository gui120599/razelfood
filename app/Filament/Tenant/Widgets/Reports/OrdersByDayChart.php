<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\OrderStatus;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Support\BrandColors;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrdersByDayChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Pedidos por dia';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->reportRange();

        $counts = $this->ordersInPeriod()
            ->toBase()
            ->selectRaw('DATE(opened_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $revenue = $this->ordersInPeriod()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->toBase()
            ->selectRaw('DATE(opened_at) as day, SUM(grand_total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $orderData = [];
        $revenueData = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $key = $day->toDateString();
            $labels[] = $day->format('d/m');
            $orderData[] = (int) ($counts[$key] ?? 0);
            $revenueData[] = round((float) ($revenue[$key] ?? 0), 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => $orderData,
                    'backgroundColor' => BrandColors::ORANGE_600,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Faturamento (R$)',
                    'data' => $revenueData,
                    'type' => 'line',
                    'borderColor' => BrandColors::TEAL_500,
                    'backgroundColor' => BrandColors::TEAL_500,
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'position' => 'left', 'ticks' => ['precision' => 0]],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]],
            ],
        ];
    }
}

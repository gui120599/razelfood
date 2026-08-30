<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Support\BrandColors;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrdersByHourChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Pedidos por hora do dia';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $byHour = $this->ordersInPeriod()
            ->toBase()
            ->selectRaw('HOUR(opened_at) as hour, COUNT(*) as total')
            ->groupBy('hour')
            ->pluck('total', 'hour');

        $data = [];
        $labels = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = sprintf('%02dh', $hour);
            $data[] = (int) ($byHour[$hour] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => 'Pedidos',
                'data' => $data,
                'backgroundColor' => BrandColors::TEAL_500,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}

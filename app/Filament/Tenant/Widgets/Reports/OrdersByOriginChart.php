<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\OrderOrigin;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Support\BrandColors;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrdersByOriginChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Pedidos por origem';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->ordersInPeriod()
            ->toBase()
            ->selectRaw('origin, COUNT(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $palette = [
            OrderOrigin::Menu->value => BrandColors::ORANGE_600,
            OrderOrigin::Staff->value => BrandColors::TEAL_500,
            OrderOrigin::Table->value => BrandColors::AMBER_300,
        ];

        $labels = [];
        $data = [];
        $colors = [];

        foreach (OrderOrigin::cases() as $origin) {
            $total = (int) ($counts[$origin->value] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = $origin->label();
            $data[] = $total;
            $colors[] = $palette[$origin->value];
        }

        return [
            'datasets' => [[
                'label' => 'Pedidos',
                'data' => $data,
                'backgroundColor' => $colors,
            ]],
            'labels' => $labels,
        ];
    }
}

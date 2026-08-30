<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\OrderStatus;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Support\BrandColors;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrdersByStatusChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Pedidos por status';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = $this->ordersInPeriod()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $palette = [
            OrderStatus::Started->value => '#94A3B8',
            OrderStatus::Open->value => BrandColors::TEAL_500,
            OrderStatus::Preparing->value => BrandColors::AMBER_300,
            OrderStatus::Ready->value => BrandColors::ORANGE_600,
            OrderStatus::InTransit->value => BrandColors::TEAL_300,
            OrderStatus::Delivered->value => BrandColors::SUCCESS,
            OrderStatus::Finished->value => '#0F766E',
            OrderStatus::Cancelled->value => BrandColors::DANGER,
        ];

        $labels = [];
        $data = [];
        $colors = [];

        foreach (OrderStatus::cases() as $status) {
            $total = (int) ($counts[$status->value] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = $status->label();
            $data[] = $total;
            $colors[] = $palette[$status->value];
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

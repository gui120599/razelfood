<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use App\Support\BrandColors;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CancellationReasonsChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Motivos de cancelamento';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = $this->ordersInPeriod()
            ->where('status', OrderStatus::Cancelled->value)
            ->toBase()
            ->selectRaw('cancellation_reason, COUNT(*) as total')
            ->groupBy('cancellation_reason')
            ->pluck('total', 'cancellation_reason');

        $labels = [];
        $data = [];

        foreach (CancellationReason::cases() as $reason) {
            $total = (int) ($counts[$reason->value] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = $reason->label();
            $data[] = $total;
        }

        return [
            'datasets' => [[
                'label' => 'Cancelamentos',
                'data' => $data,
                'backgroundColor' => BrandColors::DANGER,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}

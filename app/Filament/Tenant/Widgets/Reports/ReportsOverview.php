<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Enums\OrderStatus;
use App\Filament\Tenant\Widgets\Reports\Concerns\ResolvesReportPeriod;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class ReportsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use ResolvesReportPeriod;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $orders = $this->ordersInPeriod()->get(['status', 'grand_total', 'is_unlisted_neighborhood']);

        $total = $orders->count();
        $cancelled = $orders->where('status', OrderStatus::Cancelled);
        $billable = $orders->where('status', '!=', OrderStatus::Cancelled);

        $revenue = (float) $billable->sum('grand_total');
        $averageTicket = $billable->isNotEmpty() ? $revenue / $billable->count() : 0.0;
        $cancelRate = $total > 0 ? round($cancelled->count() / $total * 100) : 0;

        return [
            Stat::make('Pedidos no período', Number::format($total, locale: 'pt_BR'))
                ->icon(Heroicon::OutlinedShoppingBag),

            Stat::make('Faturamento', Number::currency($revenue, in: 'BRL', locale: 'pt_BR'))
                ->description('Exclui cancelados')
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->color('success'),

            Stat::make('Ticket médio', Number::currency($averageTicket, in: 'BRL', locale: 'pt_BR'))
                ->icon(Heroicon::OutlinedCalculator),

            Stat::make('Cancelamentos', $cancelled->count().' ('.$cancelRate.'%)')
                ->icon(Heroicon::OutlinedXCircle)
                ->color($cancelRate > 0 ? 'danger' : 'gray'),

            Stat::make('Fora da área mapeada', $orders->where('is_unlisted_neighborhood', true)->count())
                ->icon(Heroicon::OutlinedMapPin)
                ->color('warning'),
        ];
    }
}

<?php

namespace App\Filament\Tenant\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderUrgencyLevel;
use App\Filament\Tenant\Pages\Kitchen;
use App\Models\Order;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Orders\OrderUrgencyResolver;
use App\Support\Orders\ResolveActiveShiftStart;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class OrdersTodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public function getColumns(): int|array
    {
        return 5;
    }

    public static function canView(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::CENTRAL_DE_PEDIDOS) ?? false;
    }

    protected function getStats(): array
    {
        $tenant = CurrentTenant::get();
        $shiftStart = app(ResolveActiveShiftStart::class)();

        $ordersInShift = Order::where('opened_at', '>=', $shiftStart)->count();

        $completedInShift = Order::whereIn('status', [OrderStatus::Delivered, OrderStatus::Finished])
            ->where(fn($query) => $query
                ->where('finished_at', '>=', $shiftStart)
                ->orWhere('delivered_at', '>=', $shiftStart))
            ->get(['grand_total']);

        $revenue = (float) $completedInShift->sum('grand_total');
        $averageTicket = $completedInShift->isNotEmpty() ? $revenue / $completedInShift->count() : 0.0;

        $activeStatuses = [OrderStatus::Open, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::InTransit];
        $activeOrders = Order::whereIn('status', $activeStatuses)->get(['id', 'status', ...array_map(
            fn(OrderStatus $status) => $status->timestampColumn(),
            $activeStatuses,
        )]);

        $resolver = app(OrderUrgencyResolver::class);
        $lateCount = $activeOrders->filter(
            fn(Order $order) => $resolver->resolve(
                $order->minutesInCurrentStage(),
                $tenant?->order_attention_after_minutes ?? 15,
                $tenant?->order_late_after_minutes ?? 30,
            ) === OrderUrgencyLevel::Late
        )->count();

        return [
            Stat::make('Pedidos no turno', $ordersInShift)
                ->icon(Heroicon::OutlinedShoppingBag)
                ->columnSpan(1),

            Stat::make('Faturamento do turno', Number::currency($revenue, in: 'BRL', locale: 'pt_BR'))
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->color('success')
                ->columnSpan(1),

            Stat::make('Ticket médio', Number::currency($averageTicket, in: 'BRL', locale: 'pt_BR'))
                ->icon(Heroicon::OutlinedCalculator)
                ->columnSpan(1),

            Stat::make('Em andamento', $activeOrders->count())
                ->icon(Heroicon::OutlinedClock)
                ->color('info')
                ->columnSpan(1),

            Stat::make('Atrasados', $lateCount)
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($lateCount > 0 ? 'danger' : 'gray')
                ->url(Kitchen::getUrl()),
        ];
    }
}

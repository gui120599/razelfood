<?php

namespace App\Filament\Tenant\Pages;

use App\Actions\Reports\ExportDeliveriesCsv;
use App\Filament\Tenant\Pages\Concerns\HasReportPeriodFilter;
use App\Filament\Tenant\Widgets\Reports\DeliveriesByPersonWidget;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Relatório de entregas por entregador (RF-31) — pedidos entregues no
 * período, agrupados por entregador. Terceira "dashboard" do painel, com um
 * único widget. Gateada pela mesma feature `relatorios`.
 */
class Deliveries extends BaseDashboard
{
    // HasReportPeriodFilter::filtersForm substitui o stub do HasFiltersForm do
    // Filament — sem o `insteadof`, o PHP aborta o autoload por colisão de trait.
    use HasFiltersForm, HasReportPeriodFilter {
        HasReportPeriodFilter::filtersForm insteadof HasFiltersForm;
    }
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string $routePath = 'entregas';

    protected static ?string $slug = 'entregas';

    protected static ?string $title = 'Entregas';

    protected static ?string $navigationLabel = 'Entregas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false) && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false) && static::pageShieldShouldRegisterNavigation();
    }

    public function getWidgets(): array
    {
        return [
            DeliveriesByPersonWidget::class,
        ];
    }

    public function getColumns(): array|int
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printDeliveries')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(function (): string {
                    [$start, $end] = $this->resolvedPeriod();

                    return route('reports.deliveries.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]);
                })
                ->openUrlInNewTab(),

            Action::make('exportCsv')
                ->label('Exportar CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    [$start, $end] = $this->resolvedPeriod();

                    return app(ExportDeliveriesCsv::class)->response($start, $end);
                }),
        ];
    }
}

<?php

namespace App\Filament\Tenant\Pages;

use App\Actions\Reports\ExportOrdersCsv;
use App\Filament\Tenant\Pages\Concerns\HasReportPeriodFilter;
use App\Filament\Tenant\Widgets\Reports\CancellationReasonsChart;
use App\Filament\Tenant\Widgets\Reports\OrdersByDayChart;
use App\Filament\Tenant\Widgets\Reports\OrdersByHourChart;
use App\Filament\Tenant\Widgets\Reports\OrdersByOriginChart;
use App\Filament\Tenant\Widgets\Reports\OrdersByStatusChart;
use App\Filament\Tenant\Widgets\Reports\PaymentMethodsChart;
use App\Filament\Tenant\Widgets\Reports\ReportsOverview;
use App\Filament\Tenant\Widgets\Reports\TopProductsTable;
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
 * RF-31: dashboard de indicadores operacionais do tenant, filtrável por
 * período. Segunda "dashboard" do painel (a de `/painel` continua sendo a
 * padrão do Filament) — daí estender BaseDashboard, que já traz o grid de
 * widgets e o filtro de cabeçalho prontos.
 */
class Reports extends BaseDashboard
{
    // Alias em vez de sobrescrever direto: combinamos a checagem de feature
    // (RN-43) com a permissão do Shield, e a `canAccess()` declarada abaixo
    // já shadowa o método do trait. Mesmo padrão de Kitchen/OrderSettings.
    // HasReportPeriodFilter::filtersForm substitui o stub vazio do HasFiltersForm
    // do Filament — sem o `insteadof` explícito, o PHP aborta o autoload da classe
    // por colisão de método de trait.
    use HasFiltersForm, HasReportPeriodFilter {
        HasReportPeriodFilter::filtersForm insteadof HasFiltersForm;
    }
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string $routePath = 'relatorios';

    protected static ?string $slug = 'relatorios';

    protected static ?string $title = 'Relatórios';

    protected static ?string $navigationLabel = 'Relatórios';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 10;

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
            ReportsOverview::class,
            OrdersByDayChart::class,
            OrdersByHourChart::class,
            OrdersByStatusChart::class,
            OrdersByOriginChart::class,
            PaymentMethodsChart::class,
            CancellationReasonsChart::class,
            TopProductsTable::class,
        ];
    }

    public function getColumns(): array|int
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printOrders')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(function (): string {
                    [$start, $end] = $this->resolvedPeriod();

                    return route('reports.orders.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]);
                })
                ->openUrlInNewTab(),

            Action::make('exportCsv')
                ->label('Exportar CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    [$start, $end] = $this->resolvedPeriod();

                    return app(ExportOrdersCsv::class)->response($start, $end);
                }),
        ];
    }
}

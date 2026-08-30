<?php

namespace App\Filament\Tenant\Widgets\Reports;

use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Reports\DeliveriesReport;
use App\Support\Reports\ReportPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Relatório de entregas do período agrupado por entregador (útil para
 * tenants com mais de um Entregador). Filtra por `delivered_at`.
 */
class DeliveriesByPersonWidget extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.tenant.widgets.reports.deliveries-by-person';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false;
    }

    public function deliveryGroups(): Collection
    {
        [$start, $end] = ReportPeriod::resolveRange(
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );

        return app(DeliveriesReport::class)->groups($start, $end);
    }
}

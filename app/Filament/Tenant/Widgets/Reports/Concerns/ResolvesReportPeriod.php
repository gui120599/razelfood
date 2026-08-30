<?php

namespace App\Filament\Tenant\Widgets\Reports\Concerns;

use App\Models\Order;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base compartilhada dos widgets do dashboard de relatórios (RF-31): gate
 * por feature (RN-43) e resolução do intervalo a partir do filtro de página
 * (`$this->pageFilters`, via InteractsWithPageFilters). Todas as queries
 * passam pelo TenantScope global de Order.
 */
trait ResolvesReportPeriod
{
    public static function canView(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::RELATORIOS) ?? false;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function reportRange(): array
    {
        return ReportPeriod::resolveRange(
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );
    }

    protected function ordersInPeriod(): Builder
    {
        [$start, $end] = $this->reportRange();

        return Order::query()->openedBetween($start, $end);
    }
}

<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\Widgets\CatalogSnapshot;
use App\Filament\Tenant\Widgets\OrdersTodayOverview;
use App\Filament\Tenant\Widgets\PlanFeatures;
use App\Filament\Tenant\Widgets\StoreReadiness;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;
use Override;

/**
 * Dashboard padrão do painel do tenant (`/painel`). Lista os widgets
 * explicitamente porque agora existe uma segunda dashboard (Reports, RF-31)
 * cujos widgets também são descobertos automaticamente — sem esta lista, os
 * gráficos de relatório apareceriam aqui também.
 */
class Dashboard extends BaseDashboard
{
    
    public function getColumns(): int|array
    {
        return 5;
    }
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            OrdersTodayOverview::class,
            CatalogSnapshot::class,
            StoreReadiness::class,
            PlanFeatures::class,
        ];
    }
}

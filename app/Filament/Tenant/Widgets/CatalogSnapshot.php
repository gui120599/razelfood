<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\Category;
use App\Models\Product;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CatalogSnapshot extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::CARDAPIO_DIGITAL) ?? false;
    }

    protected function getStats(): array
    {
        $bestSeller = Product::orderByDesc('sales_count')->first(['name', 'sales_count']);

        return [
            Stat::make('Produtos ativos', Product::where('is_visible', true)->count())
                ->icon(Heroicon::OutlinedShoppingBag),

            Stat::make('Categorias', Category::count())
                ->icon(Heroicon::OutlinedRectangleStack),

            Stat::make('Mais vendido', $bestSeller?->name ?? '—')
                ->description($bestSeller ? number_format((float) $bestSeller->sales_count, 2, ',', '.').' vendas' : 'Sem vendas registradas ainda')
                ->icon(Heroicon::OutlinedStar),
        ];
    }
}

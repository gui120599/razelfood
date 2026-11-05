<?php

namespace App\Filament\Tenant\Resources\Sales;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Sales\Pages\ListSales;
use App\Filament\Tenant\Resources\Sales\Pages\ViewSale;
use App\Filament\Tenant\Resources\Sales\Schemas\SaleInfolist;
use App\Filament\Tenant\Resources\Sales\Tables\SalesTable;
use App\Models\Sale;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Vendas (fechamento de conta, Fase 1.0). Só leitura + cancelamento: a venda
 * nasce em "Fechar conta" (CloseAccount → FinalizeSale), nunca por formulário.
 */
class SaleResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'venda';

    protected static ?string $pluralModelLabel = 'vendas';

    protected static ?string $navigationLabel = 'Vendas';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return SaleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::PDV;
    }
}

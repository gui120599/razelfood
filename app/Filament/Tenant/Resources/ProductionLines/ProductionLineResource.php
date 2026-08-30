<?php

namespace App\Filament\Tenant\Resources\ProductionLines;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\ProductionLines\Pages\CreateProductionLine;
use App\Filament\Tenant\Resources\ProductionLines\Pages\EditProductionLine;
use App\Filament\Tenant\Resources\ProductionLines\Pages\ListProductionLines;
use App\Filament\Tenant\Resources\ProductionLines\Schemas\ProductionLineForm;
use App\Filament\Tenant\Resources\ProductionLines\Tables\ProductionLinesTable;
use App\Models\ProductionLine;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductionLineResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = ProductionLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?string $modelLabel = 'linha de produção';

    protected static ?string $pluralModelLabel = 'linhas de produção';

    protected static ?string $navigationLabel = 'Linhas de Produção';

    public static function form(Schema $schema): Schema
    {
        return ProductionLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionLinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionLines::route('/'),
            'create' => CreateProductionLine::route('/create'),
            'edit' => EditProductionLine::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::LINHAS_PRODUCAO;
    }
}

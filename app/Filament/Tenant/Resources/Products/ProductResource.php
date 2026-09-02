<?php

namespace App\Filament\Tenant\Resources\Products;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Products\Pages\CreateProduct;
use App\Filament\Tenant\Resources\Products\Pages\EditProduct;
use App\Filament\Tenant\Resources\Products\Pages\ListProducts;
use App\Filament\Tenant\Resources\Products\RelationManagers\AddonsRelationManager;
use App\Filament\Tenant\Resources\Products\RelationManagers\GiftsRelationManager;
use App\Filament\Tenant\Resources\Products\Schemas\ProductForm;
use App\Filament\Tenant\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Cardápio';

    protected static ?string $modelLabel = 'produto';

    protected static ?string $pluralModelLabel = 'produtos';

    protected static ?string $navigationLabel = 'Produtos';

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AddonsRelationManager::class, GiftsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CARDAPIO_DIGITAL;
    }
}

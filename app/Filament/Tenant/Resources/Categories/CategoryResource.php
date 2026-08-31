<?php

namespace App\Filament\Tenant\Resources\Categories;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Categories\Pages\CreateCategory;
use App\Filament\Tenant\Resources\Categories\Pages\EditCategory;
use App\Filament\Tenant\Resources\Categories\Pages\ListCategories;
use App\Filament\Tenant\Resources\Categories\RelationManagers\FlavorQuantityOptionsRelationManager;
use App\Filament\Tenant\Resources\Categories\RelationManagers\SubcategoriesRelationManager;
use App\Filament\Tenant\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Tenant\Resources\Categories\Tables\CategoriesTable;
use App\Models\Category;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CategoryResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Cardápio';

    protected static ?string $modelLabel = 'categoria';

    protected static ?string $pluralModelLabel = 'categorias';

    protected static ?string $navigationLabel = 'Categorias';

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SubcategoriesRelationManager::class,
            FlavorQuantityOptionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CARDAPIO_DIGITAL;
    }
}

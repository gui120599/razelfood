<?php

namespace App\Filament\Tenant\Resources\Addons;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Addons\Pages\CreateAddon;
use App\Filament\Tenant\Resources\Addons\Pages\EditAddon;
use App\Filament\Tenant\Resources\Addons\Pages\ListAddons;
use App\Filament\Tenant\Resources\Addons\Schemas\AddonForm;
use App\Filament\Tenant\Resources\Addons\Tables\AddonsTable;
use App\Models\Addon;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AddonResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = Addon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Cardápio';

    protected static ?string $modelLabel = 'adicional';

    protected static ?string $pluralModelLabel = 'adicionais';

    protected static ?string $navigationLabel = 'Adicionais';

    public static function form(Schema $schema): Schema
    {
        return AddonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AddonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAddons::route('/'),
            'create' => CreateAddon::route('/create'),
            'edit' => EditAddon::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CARDAPIO_DIGITAL;
    }
}

<?php

namespace App\Filament\Tenant\Resources\DeliveryOptions;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\DeliveryOptions\Pages\CreateDeliveryOption;
use App\Filament\Tenant\Resources\DeliveryOptions\Pages\EditDeliveryOption;
use App\Filament\Tenant\Resources\DeliveryOptions\Pages\ListDeliveryOptions;
use App\Filament\Tenant\Resources\DeliveryOptions\Schemas\DeliveryOptionForm;
use App\Filament\Tenant\Resources\DeliveryOptions\Tables\DeliveryOptionsTable;
use App\Models\DeliveryOption;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeliveryOptionResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = DeliveryOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'opção de entrega';

    protected static ?string $pluralModelLabel = 'opções de entrega';

    protected static ?string $navigationLabel = 'Entrega';

    public static function form(Schema $schema): Schema
    {
        return DeliveryOptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryOptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryOptions::route('/'),
            'create' => CreateDeliveryOption::route('/create'),
            'edit' => EditDeliveryOption::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CONFIGURACOES_ESTABELECIMENTO;
    }
}

<?php

namespace App\Filament\Tenant\Resources\DeliveryZones;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\DeliveryZones\Pages\CreateDeliveryZone;
use App\Filament\Tenant\Resources\DeliveryZones\Pages\EditDeliveryZone;
use App\Filament\Tenant\Resources\DeliveryZones\Pages\ListDeliveryZones;
use App\Filament\Tenant\Resources\DeliveryZones\RelationManagers\NeighborhoodsRelationManager;
use App\Filament\Tenant\Resources\DeliveryZones\Schemas\DeliveryZoneForm;
use App\Filament\Tenant\Resources\DeliveryZones\Tables\DeliveryZonesTable;
use App\Models\DeliveryZone;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeliveryZoneResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = DeliveryZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'setor de entrega';

    protected static ?string $pluralModelLabel = 'setores de entrega';

    protected static ?string $navigationLabel = 'Setores de Entrega';

    public static function form(Schema $schema): Schema
    {
        return DeliveryZoneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryZonesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            NeighborhoodsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryZones::route('/'),
            'create' => CreateDeliveryZone::route('/create'),
            'edit' => EditDeliveryZone::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CONFIGURACOES_ESTABELECIMENTO;
    }
}

<?php

namespace App\Filament\Resources\LocationSyncs;

use App\Filament\Resources\LocationSyncs\Pages\ListLocationSyncs;
use App\Filament\Resources\LocationSyncs\Tables\LocationSyncsTable;
use App\Models\LocationSync;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Sem CRUD tradicional — o único jeito de criar um registro é pela Action
 * "Nova sincronização" no header da listagem (ver Pages\ListLocationSyncs).
 * Toda a lógica de sincronização fica em App\Services\Address\LocationSyncService,
 * este Resource só monta a tela.
 */
class LocationSyncResource extends Resource
{
    protected static ?string $model = LocationSync::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Localidades';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'sincronização';

    protected static ?string $pluralModelLabel = 'Sincronização de Bairros';

    protected static ?string $navigationLabel = 'Sincronização de Bairros';

    public static function table(Table $table): Table
    {
        return LocationSyncsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationSyncs::route('/'),
        ];
    }
}

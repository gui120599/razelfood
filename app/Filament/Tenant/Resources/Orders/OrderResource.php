<?php

namespace App\Filament\Tenant\Resources\Orders;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Orders\Pages\ListOrders;
use App\Filament\Tenant\Resources\Orders\Pages\ViewOrder;
use App\Filament\Tenant\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Tenant\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Tenant\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    protected static ?string $navigationLabel = 'Histórico de Pedidos';

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::HISTORICO_PEDIDOS;
    }
}

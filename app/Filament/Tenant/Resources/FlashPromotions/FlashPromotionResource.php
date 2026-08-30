<?php

namespace App\Filament\Tenant\Resources\FlashPromotions;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\FlashPromotions\Pages\CreateFlashPromotion;
use App\Filament\Tenant\Resources\FlashPromotions\Pages\EditFlashPromotion;
use App\Filament\Tenant\Resources\FlashPromotions\Pages\ListFlashPromotions;
use App\Filament\Tenant\Resources\FlashPromotions\RelationManagers\ProductsRelationManager;
use App\Filament\Tenant\Resources\FlashPromotions\Schemas\FlashPromotionForm;
use App\Filament\Tenant\Resources\FlashPromotions\Tables\FlashPromotionsTable;
use App\Models\FlashPromotion;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FlashPromotionResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = FlashPromotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Cardápio';

    protected static ?string $modelLabel = 'promoção relâmpago';

    protected static ?string $pluralModelLabel = 'promoções relâmpago';

    protected static ?string $navigationLabel = 'Promoções Relâmpago';

    public static function form(Schema $schema): Schema
    {
        return FlashPromotionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlashPromotionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlashPromotions::route('/'),
            'create' => CreateFlashPromotion::route('/create'),
            'edit' => EditFlashPromotion::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CARDAPIO_DIGITAL;
    }
}

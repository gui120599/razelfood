<?php

namespace App\Filament\Tenant\Resources\CardBrands;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\CardBrands\Pages\CreateCardBrand;
use App\Filament\Tenant\Resources\CardBrands\Pages\EditCardBrand;
use App\Filament\Tenant\Resources\CardBrands\Pages\ListCardBrands;
use App\Filament\Tenant\Resources\CardBrands\Schemas\CardBrandForm;
use App\Filament\Tenant\Resources\CardBrands\Tables\CardBrandsTable;
use App\Models\CardBrand;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Bandeiras de cartão do estabelecimento (bandeira da NFE.io + CNPJ da credenciadora),
 * usadas no fechamento da conta e no bloco `card` da NFC-e. Só com a feature `nfe_emissao`.
 */
class CardBrandResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = CardBrand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'bandeira de cartão';

    protected static ?string $pluralModelLabel = 'bandeiras de cartão';

    protected static ?string $navigationLabel = 'Bandeiras de cartão';

    public static function form(Schema $schema): Schema
    {
        return CardBrandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardBrandsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCardBrands::route('/'),
            'create' => CreateCardBrand::route('/create'),
            'edit' => EditCardBrand::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::NFE_EMISSAO;
    }
}

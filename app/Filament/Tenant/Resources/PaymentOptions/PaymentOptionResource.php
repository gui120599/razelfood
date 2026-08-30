<?php

namespace App\Filament\Tenant\Resources\PaymentOptions;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\PaymentOptions\Pages\CreatePaymentOption;
use App\Filament\Tenant\Resources\PaymentOptions\Pages\EditPaymentOption;
use App\Filament\Tenant\Resources\PaymentOptions\Pages\ListPaymentOptions;
use App\Filament\Tenant\Resources\PaymentOptions\Schemas\PaymentOptionForm;
use App\Filament\Tenant\Resources\PaymentOptions\Tables\PaymentOptionsTable;
use App\Models\PaymentOption;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PaymentOptionResource extends Resource
{
    use GatedByFeature;

    protected static ?string $model = PaymentOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $modelLabel = 'opção de pagamento';

    protected static ?string $pluralModelLabel = 'opções de pagamento';

    protected static ?string $navigationLabel = 'Pagamento';

    public static function form(Schema $schema): Schema
    {
        return PaymentOptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentOptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentOptions::route('/'),
            'create' => CreatePaymentOption::route('/create'),
            'edit' => EditPaymentOption::route('/{record}/edit'),
        ];
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::CONFIGURACOES_ESTABELECIMENTO;
    }
}

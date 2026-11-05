<?php

namespace App\Filament\Tenant\Resources\PaymentOptions\Schemas;

use App\Enums\FiscalPaymentMethod;
use App\Filament\Support\ProductFiscalFields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da opção de pagamento')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('show_in_menu')
                        ->label('Exibir no checkout')
                        ->default(true),
                    Toggle::make('is_cash')
                        ->label('É dinheiro')
                        ->helperText('Ativa o campo de troco no checkout.'),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->columnSpanFull(),
                ]),
            Section::make('Dados fiscais')
                ->description('Como esta forma aparece na NFC-e. O código fiscal é escolhido aqui — nunca deduzido pelo nome da forma.')
                ->columns(2)
                ->visible(fn (): bool => ProductFiscalFields::tenantUsesFiscal())
                ->schema([
                    Select::make('fiscal_payment_code')
                        ->label('Meio de pagamento na nota')
                        ->options(collect(FiscalPaymentMethod::cases())->mapWithKeys(
                            fn (FiscalPaymentMethod $method): array => [$method->value => $method->label()]
                        )->all())
                        ->searchable()
                        ->columnSpanFull(),
                    Toggle::make('auto_issue_fiscal_document')
                        ->label('Emitir NFC-e automaticamente')
                        ->helperText('Vendas pagas com esta forma geram a nota sozinhas.'),
                    Toggle::make('requires_card_brand')
                        ->label('Exigir bandeira no fechamento da conta'),
                    Toggle::make('requires_authorization_code')
                        ->label('Exigir código de autorização no fechamento da conta'),
                ]),
        ]);
    }
}

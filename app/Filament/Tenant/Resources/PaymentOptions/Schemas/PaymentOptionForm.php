<?php

namespace App\Filament\Tenant\Resources\PaymentOptions\Schemas;

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
        ]);
    }
}

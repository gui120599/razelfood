<?php

namespace App\Filament\Tenant\Resources\Addons\Schemas;

use App\Filament\Support\InputMasks;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AddonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do adicional')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->columnSpanFull(),
                ]),
            Section::make('Preço')
                ->columns(3)
                ->schema([
                    InputMasks::money(
                        TextInput::make('price')
                            ->label('Preço base')
                            ->prefix('R$')
                            ->required()
                    ),
                ]),
            Section::make('Disponibilidade')
                ->columns(3)
                ->schema([
                    Toggle::make('controls_stock')
                        ->label('Controla estoque')
                        ->live(),
                    TextInput::make('stock_quantity')
                        ->label('Quantidade em estoque')
                        ->numeric()
                        ->visible(fn (Get $get): bool => (bool) $get('controls_stock')),
                    Toggle::make('show_when_out_of_stock')
                        ->label('Exibir mesmo sem estoque')
                        ->visible(fn (Get $get): bool => (bool) $get('controls_stock')),
                ]),
        ]);
    }
}

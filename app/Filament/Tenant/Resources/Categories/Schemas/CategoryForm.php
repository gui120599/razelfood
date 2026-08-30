<?php

namespace App\Filament\Tenant\Resources\Categories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da categoria')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Toggle::make('show_in_menu')
                        ->label('Exibir no cardápio')
                        ->default(true),
                    Toggle::make('allows_flavors')
                        ->label('Permite sabores (meio a meio)')
                        ->helperText('Depois de salvar, configure as quantidades de sabores disponíveis na aba "Quantidades de sabores".'),
                ]),
        ]);
    }
}

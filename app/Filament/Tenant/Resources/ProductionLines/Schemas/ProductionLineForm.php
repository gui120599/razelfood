<?php

namespace App\Filament\Tenant\Resources\ProductionLines\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Linha de produção')
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('categories')
                        ->label('Categorias')
                        ->relationship('categories', 'name')
                        ->multiple()
                        ->preload()
                        ->required()
                        ->helperText('Pedidos com pelo menos um item de uma dessas categorias aparecem na Central quando esta linha estiver selecionada.'),
                ]),
        ]);
    }
}

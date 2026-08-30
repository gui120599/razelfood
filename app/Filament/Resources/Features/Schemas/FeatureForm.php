<?php

namespace App\Filament\Resources\Features\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeatureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Feature')
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->label('Chave técnica')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Identificador usado no código (ex.: pdv). Evite editar depois de criado.'),
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->columnSpanFull(),
                    Toggle::make('is_available')
                        ->label('Disponível')
                        ->default(true)
                        ->helperText('Desligado = reservada no catálogo, sem implementação funcional ainda. Nunca fica liberada para nenhum tenant, mesmo constando num plano ou com override.'),
                    TextInput::make('display_order')
                        ->label('Ordem de exibição')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ]),
        ]);
    }
}

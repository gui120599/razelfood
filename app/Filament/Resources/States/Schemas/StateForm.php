<?php

namespace App\Filament\Resources\States\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),

            TextInput::make('uf')
                ->label('UF')
                ->required()
                ->maxLength(2)
                ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null ? strtoupper($state) : null)
                ->unique(ignoreRecord: true),

            TextInput::make('ibge_code')
                ->label('Código IBGE')
                ->numeric()
                ->maxLength(2)
                ->unique(ignoreRecord: true),
        ]);
    }
}

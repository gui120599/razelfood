<?php

namespace App\Filament\Tenant\Resources\DeliveryZones\Schemas;

use App\Filament\Support\InputMasks;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeliveryZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do setor')
                ->description('Cada setor agrupa um ou mais bairros com a mesma taxa de entrega. Cadastre os bairros depois de salvar o setor.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome do setor')
                        ->placeholder('Ex.: Centro, Zona Sul')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    InputMasks::money(
                        TextInput::make('delivery_fee')
                            ->label('Taxa de entrega')
                            ->prefix('R$')
                            ->required()
                            ->default(0)
                    ),
                ]),
        ]);
    }
}

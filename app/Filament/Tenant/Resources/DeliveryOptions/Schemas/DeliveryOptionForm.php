<?php

namespace App\Filament\Tenant\Resources\DeliveryOptions\Schemas;

use App\Filament\Support\InputMasks;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DeliveryOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da opção de entrega')
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
                    Toggle::make('requires_address')
                        ->label('Exige endereço de entrega')
                        ->default(true)
                        ->live()
                        ->helperText('Ligado: o cliente informa endereço no checkout e a taxa é resolvida pelo bairro (Setores de Entrega). Desligado: opção sem endereço — ex.: retirada, consumo no local — usa sempre a taxa fixa ao lado.'),
                    InputMasks::money(
                        TextInput::make('delivery_fee')
                            ->label('Taxa')
                            ->helperText(fn (Get $get) => $get('requires_address')
                                ? 'Usada só enquanto nenhum setor de entrega estiver cadastrado. Depois disso, a taxa passa a ser resolvida pelo bairro do cliente — configure em "Setores de Entrega".'
                                : 'Taxa fixa desta opção (ex.: taxa de serviço). Deixe 0 se não cobra nada.')
                            ->prefix('R$')
                            ->required()
                            ->default(0)
                    ),
                    InputMasks::money(
                        TextInput::make('min_order_for_free_delivery')
                            ->label('Valor mínimo p/ isenção')
                            ->prefix('R$')
                            ->helperText('Vazio = sem isenção por valor mínimo.')
                    ),
                ]),
        ]);
    }
}

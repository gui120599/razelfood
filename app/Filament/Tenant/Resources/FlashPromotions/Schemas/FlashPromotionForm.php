<?php

namespace App\Filament\Tenant\Resources\FlashPromotions\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FlashPromotionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da promoção')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->label('Ativa')
                        ->default(true),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->columnSpanFull(),
                ]),
            Section::make('Janela de vigência')
                ->schema([
                    Toggle::make('is_recurring')
                        ->label('Recorrente (dias da semana)')
                        ->helperText('Desligado = janela pontual com data/hora de início e fim.')
                        ->live(),
                    DateTimePicker::make('starts_at')
                        ->label('Início')
                        ->visible(fn (Get $get): bool => ! $get('is_recurring')),
                    DateTimePicker::make('ends_at')
                        ->label('Fim')
                        ->visible(fn (Get $get): bool => ! $get('is_recurring')),
                    CheckboxList::make('weekdays')
                        ->label('Dias da semana')
                        ->options([
                            0 => 'Domingo',
                            1 => 'Segunda',
                            2 => 'Terça',
                            3 => 'Quarta',
                            4 => 'Quinta',
                            5 => 'Sexta',
                            6 => 'Sábado',
                        ])
                        ->helperText('Nenhum selecionado = todos os dias.')
                        ->columns(4)
                        ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),
                    TimePicker::make('start_time')
                        ->label('Horário de início')
                        ->seconds(false)
                        ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),
                    TimePicker::make('end_time')
                        ->label('Horário de fim')
                        ->seconds(false)
                        ->helperText('Pode ser menor que o de início (ex.: 22h–02h cruzando a meia-noite).')
                        ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),
                    DatePicker::make('recurrence_end_date')
                        ->label('Encerrar recorrência em')
                        ->visible(fn (Get $get): bool => (bool) $get('is_recurring')),
                ]),
            Section::make('Estoque promocional e limites')
                ->columns(2)
                ->schema([
                    TextInput::make('total_quantity')
                        ->label('Teto de unidades')
                        ->numeric()
                        ->helperText('Vazio = sem teto.'),
                    TextInput::make('per_order_limit')
                        ->label('Limite por pedido')
                        ->numeric(),
                    Toggle::make('show_counter')
                        ->label('Exibir contador de escassez')
                        ->live(),
                    TextInput::make('scarcity_threshold')
                        ->label('A partir de quantas unidades restantes')
                        ->numeric()
                        ->visible(fn (Get $get): bool => (bool) $get('show_counter')),
                ]),
            Section::make('Sabores')
                ->columns(2)
                ->schema([
                    Toggle::make('allows_flavors')
                        ->label('Permite sabores (meio a meio)')
                        ->live(),
                    TextInput::make('max_flavors')
                        ->label('Máximo de sabores')
                        ->numeric()
                        ->minValue(2)
                        ->visible(fn (Get $get): bool => (bool) $get('allows_flavors')),
                ]),
        ]);
    }
}

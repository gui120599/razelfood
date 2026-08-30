<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plano')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create'
                            ? $set('slug', Str::slug($state ?? ''))
                            : null),
                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Ativo')
                        ->default(true),
                    TextInput::make('display_order')
                        ->label('Ordem de exibição')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ]),
            Section::make('Features do plano')
                ->description('Features reservadas (sem implementação funcional) podem constar do plano, mas só ficam liberadas de fato quando marcadas como disponíveis no catálogo.')
                ->schema([
                    CheckboxList::make('features')
                        ->label('')
                        ->relationship(titleAttribute: 'name')
                        ->columns(2)
                        ->searchable(),
                ]),
        ]);
    }
}

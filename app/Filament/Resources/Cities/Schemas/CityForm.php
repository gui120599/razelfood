<?php

namespace App\Filament\Resources\Cities\Schemas;

use App\Models\City;
use App\Support\NeighborhoodNormalizer;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('state_id')
                ->label('Estado')
                ->relationship('state', 'name')
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->rules([self::uniqueNamePerStateRule()]),

            TextInput::make('ibge_code')
                ->label('Código IBGE')
                ->numeric()
                ->unique(ignoreRecord: true),
        ]);
    }

    /**
     * A unicidade real (`cities.state_id` + `normalized_name`) é sobre uma
     * coluna auxiliar normalizada, não sobre `name` diretamente — o
     * `->unique()` padrão do Filament não serve aqui.
     */
    private static function uniqueNamePerStateRule(): Closure
    {
        return function (Get $get, ?Model $record): Closure {
            return function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                $exists = City::query()
                    ->where('state_id', $get('state_id'))
                    ->where('normalized_name', NeighborhoodNormalizer::normalize($value))
                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->exists();

                if ($exists) {
                    $fail('Já existe uma cidade com este nome neste estado.');
                }
            };
        };
    }
}

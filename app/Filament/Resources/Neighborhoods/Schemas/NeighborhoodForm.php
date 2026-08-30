<?php

namespace App\Filament\Resources\Neighborhoods\Schemas;

use App\Models\City;
use App\Models\Neighborhood;
use App\Support\NeighborhoodNormalizer;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class NeighborhoodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('city_id')
                ->label('Cidade')
                ->relationship('city', 'name', modifyQueryUsing: fn ($query) => $query->with('state'))
                ->getOptionLabelFromRecordUsing(fn (City $record): string => "{$record->name}/{$record->state->uf}")
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255)
                ->rules([self::uniqueNamePerCityRule()]),
        ]);
    }

    /**
     * A unicidade real (`neighborhoods.city_id` + `normalized_name`) é sobre
     * uma coluna auxiliar normalizada, não sobre `name` diretamente — o
     * `->unique()` padrão do Filament não serve aqui.
     */
    private static function uniqueNamePerCityRule(): Closure
    {
        return function (Get $get, ?Model $record): Closure {
            return function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                $exists = Neighborhood::query()
                    ->where('city_id', $get('city_id'))
                    ->where('normalized_name', NeighborhoodNormalizer::normalize($value))
                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->exists();

                if ($exists) {
                    $fail('Já existe um bairro com este nome nesta cidade.');
                }
            };
        };
    }
}

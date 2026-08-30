<?php

namespace App\Filament\Resources\Cities\RelationManagers;

use App\Models\Neighborhood;
use App\Support\NeighborhoodNormalizer;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Bairros do catálogo global desta cidade (App\Models\Neighborhood) —
 * gerenciados pelo Super Admin no painel central. Não confundir com
 * App\Filament\Tenant\Resources\DeliveryZones\RelationManagers\NeighborhoodsRelationManager
 * (mesmo nome de classe, painel do tenant, gerencia DeliveryZoneNeighborhood
 * — texto livre, sem relação com este catálogo).
 */
class NeighborhoodsRelationManager extends RelationManager
{
    protected static string $relationship = 'neighborhoods';

    protected static ?string $title = 'Bairros';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome do Bairro')
                ->required()
                ->maxLength(255)
                ->rules([$this->uniqueNamePerCityRule()]),
        ]);
    }

    private function uniqueNamePerCityRule(): Closure
    {
        return function (?Model $record): Closure {
            $cityId = $this->getOwnerRecord()->id;

            return function (string $attribute, $value, Closure $fail) use ($cityId, $record): void {
                $exists = Neighborhood::query()
                    ->where('city_id', $cityId)
                    ->where('normalized_name', NeighborhoodNormalizer::normalize($value))
                    ->when($record, fn($query) => $query->whereKeyNot($record->getKey()))
                    ->exists();

                if ($exists) {
                    $fail('Já existe um bairro com este nome nesta cidade.');
                }
            };
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel('Bairro')
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

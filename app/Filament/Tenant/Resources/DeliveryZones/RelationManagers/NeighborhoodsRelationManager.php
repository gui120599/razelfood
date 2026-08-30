<?php

namespace App\Filament\Tenant\Resources\DeliveryZones\RelationManagers;

use App\Models\City;
use App\Models\DeliveryZoneNeighborhood;
use App\Models\Neighborhood;
use App\Support\NeighborhoodNormalizer;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bairros atendidos por este setor (RN-34). Um bairro pertence a no máximo
 * um setor por tenant (RN-35) — reforçado pelo índice único em
 * `delivery_zone_neighborhoods` (tenant_id, neighborhood, city).
 *
 * `city`/`neighborhood` continuam sendo texto livre normalizado em
 * `delivery_zone_neighborhoods` (ver NeighborhoodNormalizer) — isso não
 * muda. O que muda é a ORIGEM das opções dos Selects: em vez de valores já
 * usados pelo próprio tenant, vêm do catálogo global sincronizado pelo
 * Super Admin (App\Models\City/App\Models\Neighborhood, alimentado por
 * App\Services\Address\LocationSyncService via IBGE + ViaCEP). Por isso o
 * formulário não consulta IBGE/ViaCEP ao abrir — só lê a base local.
 */
class NeighborhoodsRelationManager extends RelationManager
{
    protected static string $relationship = 'neighborhoods';

    protected static ?string $title = 'Bairros';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('city')
                ->label('Cidade')
                ->options(fn (): array => $this->cityOptions())
                ->searchable()
                ->live()
                ->required()
                ->afterStateUpdated(fn (Set $set) => $set('neighborhood', null))
                ->helperText('Bairros vêm da base sincronizada pelo Super Admin. Se sua cidade não aparecer aqui, peça para o suporte rodar a sincronização dessa cidade.'),
            Select::make('neighborhood')
                ->label('Bairro')
                ->columnSpanFull()
                ->options(fn (Get $get): array => $this->neighborhoodOptions($get('city')))
                ->searchable()
                ->required()
                ->disabled(fn (Get $get): bool => blank($get('city')))
                ->rules([
                    // Garante no servidor (não só na lista de opções da UI)
                    // que o bairro escolhido realmente pertence à cidade
                    // selecionada — a lista de opções sozinha não impede um
                    // valor de outra cidade chegar ao submit.
                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                        $belongsToCity = Neighborhood::query()
                            ->whereHas('city', fn ($query) => $query->where('normalized_name', $get('city')))
                            ->where('normalized_name', $value)
                            ->exists();

                        if (! $belongsToCity) {
                            $fail('Este bairro não pertence à cidade selecionada.');
                        }
                    },
                    fn (Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                        $exists = DeliveryZoneNeighborhood::query()
                            ->where('neighborhood', NeighborhoodNormalizer::normalize($value))
                            ->where('city', NeighborhoodNormalizer::normalize($get('city')))
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($exists) {
                            $fail('Este bairro já está cadastrado para esta cidade em outro setor.');
                        }
                    },
                ])
                ->helperText('Somente bairros já cadastrados para a cidade selecionada.'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function cityOptions(): array
    {
        return City::query()
            ->orderBy('name')
            ->pluck('name', 'normalized_name')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function neighborhoodOptions(?string $normalizedCity): array
    {
        if (blank($normalizedCity)) {
            return [];
        }

        $city = City::query()->where('normalized_name', $normalizedCity)->first();

        if (! $city) {
            return [];
        }

        return Neighborhood::query()
            ->where('city_id', $city->id)
            ->orderBy('name')
            ->pluck('name', 'normalized_name')
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('neighborhood')
            ->modelLabel('Bairros')
            ->columns([
                TextColumn::make('neighborhood')
                    ->label('Bairro')
                    ->formatStateUsing(fn (?string $state) => $state ? Str::title($state) : null)
                    ->searchable(),
                TextColumn::make('city')
                    ->label('Cidade')
                    ->formatStateUsing(fn (?string $state) => $state ? Str::title($state) : null)
                    ->placeholder('—'),
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

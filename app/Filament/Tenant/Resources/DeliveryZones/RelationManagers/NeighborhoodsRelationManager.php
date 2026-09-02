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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
 *
 * Para agilizar o cadastro em lote, o "Adicionar bairros" aceita VÁRIOS
 * bairros de uma vez (um registro por bairro) e lembra a última cidade
 * usada (`$lastCity`), pré-selecionando-a no próximo cadastro.
 */
class NeighborhoodsRelationManager extends RelationManager
{
    protected static string $relationship = 'neighborhoods';

    protected static ?string $title = 'Bairros';

    /**
     * Última cidade (normalizada) usada num cadastro de bairros — pré-seleciona
     * no próximo "Adicionar bairros" para não ter que escolher de novo.
     */
    public ?string $lastCity = null;

    /**
     * Formulário do EditAction: edita UM bairro por vez.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->cityField(),
            Select::make('neighborhood')
                ->label('Bairro')
                ->columnSpanFull()
                ->options(fn (Get $get): array => $this->neighborhoodOptions($get('city')))
                ->searchable()
                ->required()
                ->disabled(fn (Get $get): bool => blank($get('city')))
                ->rules([
                    $this->neighborhoodBelongsToCityRule(multiple: false),
                    $this->neighborhoodNotTakenRule(multiple: false),
                ])
                ->helperText('Somente bairros já cadastrados para a cidade selecionada.'),
        ]);
    }

    /**
     * Formulário do CreateAction: escolhe a cidade uma vez e vários bairros.
     *
     * @return array<int, Component>
     */
    private function createFormSchema(): array
    {
        return [
            $this->cityField()
                ->default(fn (): ?string => $this->lastCity)
                ->afterStateUpdated(fn (Set $set) => $set('neighborhoods', [])),
            Select::make('neighborhoods')
                ->label('Bairros')
                ->columnSpanFull()
                ->multiple()
                ->options(fn (Get $get): array => $this->neighborhoodOptions($get('city')))
                ->searchable()
                ->required()
                ->disabled(fn (Get $get): bool => blank($get('city')))
                ->rules([
                    $this->neighborhoodBelongsToCityRule(multiple: true),
                    $this->neighborhoodNotTakenRule(multiple: true),
                ])
                ->helperText('Selecione quantos bairros quiser — cada um vira um registro deste setor.'),
        ];
    }

    private function cityField(): Select
    {
        return Select::make('city')
            ->label('Cidade')
            ->options(fn (): array => $this->cityOptions())
            ->searchable()
            ->live()
            ->required()
            ->afterStateUpdated(fn (Set $set) => $set('neighborhood', null))
            ->helperText('Bairros vêm da base sincronizada pelo Super Admin. Se sua cidade não aparecer aqui, peça para o suporte rodar a sincronização dessa cidade.');
    }

    /**
     * Garante no servidor (não só na lista de opções da UI) que o(s) bairro(s)
     * escolhido(s) realmente pertence(m) à cidade selecionada.
     */
    private function neighborhoodBelongsToCityRule(bool $multiple): Closure
    {
        return fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get, $multiple): void {
            $city = City::query()->where('normalized_name', $get('city'))->first();

            if (! $city) {
                return;
            }

            $selected = $this->normalizedValues($value, $multiple);

            $valid = Neighborhood::query()
                ->where('city_id', $city->id)
                ->whereIn('normalized_name', $selected)
                ->pluck('normalized_name');

            $foreign = $selected->diff($valid);

            if ($foreign->isNotEmpty()) {
                $fail($multiple
                    ? 'Estes bairros não pertencem à cidade selecionada: '.$this->humanList($foreign).'.'
                    : 'Este bairro não pertence à cidade selecionada.');
            }
        };
    }

    /**
     * Reforça a RN-35: um bairro/cidade só pode estar em um setor do tenant.
     */
    private function neighborhoodNotTakenRule(bool $multiple): Closure
    {
        return fn (Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record, $multiple): void {
            $normalizedCity = NeighborhoodNormalizer::normalize($get('city'));
            $selected = $this->normalizedValues($value, $multiple);

            $taken = DeliveryZoneNeighborhood::query()
                ->where('city', $normalizedCity)
                ->whereIn('neighborhood', $selected)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->pluck('neighborhood');

            if ($taken->isNotEmpty()) {
                $fail($multiple
                    ? 'Estes bairros já estão cadastrados para esta cidade em outro setor: '.$this->humanList($taken).'.'
                    : 'Este bairro já está cadastrado para esta cidade em outro setor.');
            }
        };
    }

    /**
     * @return Collection<int, string>
     */
    private function normalizedValues(mixed $value, bool $multiple): Collection
    {
        return collect($multiple ? ($value ?? []) : [$value])
            ->filter(fn ($item) => filled($item))
            ->map(fn ($item) => NeighborhoodNormalizer::normalize($item))
            ->values();
    }

    /**
     * @param  Collection<int, string>  $normalized
     */
    private function humanList(Collection $normalized): string
    {
        return $normalized->map(fn (string $name) => Str::title($name))->implode(', ');
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
                CreateAction::make()
                    ->label('Adicionar bairros')
                    ->modalHeading('Adicionar bairros ao setor')
                    ->schema($this->createFormSchema())
                    ->using(function (array $data, NeighborhoodsRelationManager $livewire): ?Model {
                        $zone = $livewire->getOwnerRecord();
                        $cityId = City::query()->where('normalized_name', $data['city'])->value('id');

                        $created = collect($data['neighborhoods'])->map(
                            fn (string $neighborhood): Model => $zone->neighborhoods()->create([
                                'city_id' => $cityId,
                                'city' => $data['city'],
                                'neighborhood' => $neighborhood,
                            ]),
                        );

                        $livewire->lastCity = $data['city'];

                        return $created->last();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'city_id' => City::query()->where('normalized_name', $data['city'])->value('id'),
                    ]),
                DeleteAction::make(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\LocationSyncs\Pages;

use App\Filament\Resources\LocationSyncs\LocationSyncResource;
use App\Filament\Resources\LocationSyncs\Schemas\LocationSyncForm;
use App\Jobs\ImportNeighborhoodsFromRuaCepJob;
use App\Services\Address\IbgeService;
use App\Services\Address\LocationSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ListLocationSyncs extends ListRecords
{
    protected static string $resource = LocationSyncResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Nova sincronização')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalHeading('Sincronizar bairros')
                ->modalSubmitActionLabel('Sincronizar Bairros')
                ->schema(fn (): array => LocationSyncForm::schema())
                ->action(function (array $data, Schema $schema): void {
                    $uf = $data['uf'];
                    $cityIbgeCode = (int) $data['city_ibge_code'];

                    $cityData = collect(app(IbgeService::class)->citiesOf($uf))
                        ->firstWhere('ibge_code', $cityIbgeCode);

                    try {
                        $sync = app(LocationSyncService::class)->startSync([
                            'uf' => $uf,
                            'city_ibge_code' => $cityIbgeCode,
                            'city_name' => $cityData['name'] ?? (string) $cityIbgeCode,
                            'cep_start' => (int) preg_replace('/\D/', '', (string) $data['cep_start']),
                            'cep_end' => (int) preg_replace('/\D/', '', (string) $data['cep_end']),
                        ]);
                    } catch (ValidationException $exception) {
                        // O erro nasce em LocationSyncService (fora do ciclo
                        // de validação do schema), então a chave chega "crua"
                        // (ex.: "city_ibge_code") — precisa ser reprefixada
                        // com o state path da action pra aparecer no campo
                        // certo do modal em vez de se perder no error bag.
                        $statePath = $schema->getStatePath();

                        throw ValidationException::withMessages(
                            collect($exception->errors())
                                ->mapWithKeys(fn (array $messages, string $key) => [
                                    filled($statePath) ? "{$statePath}.{$key}" : $key => $messages,
                                ])
                                ->all(),
                        );
                    }

                    Notification::make()
                        ->title('Sincronização iniciada')
                        ->body("{$sync->total_ceps} CEPs na fila para {$sync->city->name}/{$sync->state->uf}.")
                        ->success()
                        ->send();
                }),

            Action::make('importFromRuaCep')
                ->label('Importar do RuaCEP')
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->color('gray')
                ->modalHeading('Importar bairros do RuaCEP')
                ->modalDescription('Fonte extra, complementar ao sweep de CEP: busca a listagem de bairros da cidade direto no site ruacep.com.br (terceiro não-oficial). Não precisa de faixa de CEP — roda rápido, em background.')
                ->modalSubmitActionLabel('Importar')
                ->schema(fn (): array => LocationSyncForm::stateAndCityFields())
                ->action(function (array $data): void {
                    $uf = $data['uf'];
                    $cityIbgeCode = (int) $data['city_ibge_code'];

                    $cityData = collect(app(IbgeService::class)->citiesOf($uf))
                        ->firstWhere('ibge_code', $cityIbgeCode);

                    $city = app(LocationSyncService::class)->resolveStateAndCity(
                        $uf,
                        $cityData['name'] ?? (string) $cityIbgeCode,
                        $cityIbgeCode,
                    );

                    ImportNeighborhoodsFromRuaCepJob::dispatch($city->id);

                    Notification::make()
                        ->title('Importação iniciada')
                        ->body("Buscando bairros de {$city->name}/{$city->state->uf} no RuaCEP.")
                        ->success()
                        ->send();
                }),
        ];
    }
}

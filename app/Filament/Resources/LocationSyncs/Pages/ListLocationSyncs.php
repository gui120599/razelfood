<?php

namespace App\Filament\Resources\LocationSyncs\Pages;

use App\Filament\Resources\LocationSyncs\LocationSyncResource;
use App\Filament\Resources\LocationSyncs\Schemas\LocationSyncForm;
use App\Jobs\ImportNeighborhoodsFromRuaCepJob;
use App\Services\Address\IbgeService;
use App\Services\Address\LocationCatalogTransfer;
use App\Services\Address\LocationSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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

            Action::make('exportCatalog')
                ->label('Exportar localidades')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->tooltip('Baixa estados, cidades e bairros num arquivo .json pra reimportar em outro ambiente.')
                ->action(fn () => app(LocationCatalogTransfer::class)->downloadResponse()),

            Action::make('importCatalog')
                ->label('Importar localidades')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalHeading('Importar localidades')
                ->modalDescription('Envie o arquivo .json gerado pelo "Exportar localidades" em outro ambiente. Estados, cidades e bairros são inseridos ou atualizados por chave natural (UF, código IBGE, nome normalizado) — nada é apagado.')
                ->modalSubmitActionLabel('Importar')
                ->schema([
                    FileUpload::make('file')
                        ->label('Arquivo JSON')
                        ->acceptedFileTypes(['application/json'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data, Schema $schema): void {
                    $file = $data['file'];
                    $contents = $file instanceof TemporaryUploadedFile ? $file->get() : false;
                    $payload = is_string($contents) ? json_decode($contents, true) : null;

                    try {
                        if ($payload === null) {
                            throw ValidationException::withMessages([
                                'file' => 'Não foi possível ler o arquivo enviado como JSON.',
                            ]);
                        }

                        $counts = app(LocationCatalogTransfer::class)->import($payload);
                    } catch (ValidationException $exception) {
                        // Erros nascem em LocationCatalogTransfer com a chave "crua"
                        // ("file") — reprefixa com o state path da action pra
                        // aparecer no campo certo do modal (mesmo padrão da action "sync").
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
                        ->title('Localidades importadas')
                        ->body("{$counts['states']} estados, {$counts['cities']} cidades e {$counts['neighborhoods']} bairros processados.")
                        ->success()
                        ->send();
                }),
        ];
    }
}

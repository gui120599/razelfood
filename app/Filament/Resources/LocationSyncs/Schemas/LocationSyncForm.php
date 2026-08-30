<?php

namespace App\Filament\Resources\LocationSyncs\Schemas;

use App\Filament\Support\InputMasks;
use App\Services\Address\IbgeService;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Schema usado só dentro da Action "Nova sincronização"
 * (Pages\ListLocationSyncs) — não há Create/Edit page tradicional.
 */
class LocationSyncForm
{
    /**
     * Os 2 Selects de Estado/Cidade (IBGE ao vivo) — reaproveitados tanto
     * pelo schema() do sweep de CEP quanto pela Action "Importar do RuaCEP"
     * (Pages\ListLocationSyncs), que não precisa dos campos de CEP.
     *
     * @return array<int, Component>
     */
    public static function stateAndCityFields(): array
    {
        return [
            Select::make('uf')
                ->label('Estado')
                ->options(fn (): array => collect(app(IbgeService::class)->states())
                    ->mapWithKeys(fn (array $state): array => [$state['uf'] => "{$state['name']} ({$state['uf']})"])
                    ->all())
                ->searchable()
                ->live()
                ->required()
                ->afterStateUpdated(fn (Set $set) => $set('city_ibge_code', null)),

            Select::make('city_ibge_code')
                ->label('Cidade')
                ->options(fn (Get $get): array => filled($get('uf'))
                    ? collect(app(IbgeService::class)->citiesOf($get('uf')))
                        ->mapWithKeys(fn (array $city): array => [$city['ibge_code'] => $city['name']])
                        ->all()
                    : [])
                ->searchable()
                ->live()
                ->required()
                ->disabled(fn (Get $get) => blank($get('uf')))
                ->helperText('Selecione o Estado primeiro.'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        return [
            ...self::stateAndCityFields(),

            InputMasks::cep(TextInput::make('cep_start')->label('CEP inicial'))
                ->required()
                ->live(onBlur: true),

            InputMasks::cep(TextInput::make('cep_end')->label('CEP final'))
                ->required()
                ->live(onBlur: true)
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                        $start = (int) preg_replace('/\D/', '', (string) $get('cep_start'));
                        $end = (int) preg_replace('/\D/', '', $value);

                        if ($start > 0 && $end > 0 && $end < $start) {
                            $fail('O CEP final deve ser maior ou igual ao CEP inicial.');
                        }
                    },
                ]),

            Placeholder::make('range_summary')
                ->label('Resumo da faixa')
                ->content(function (Get $get): string {
                    $start = (int) preg_replace('/\D/', '', (string) $get('cep_start'));
                    $end = (int) preg_replace('/\D/', '', (string) $get('cep_end'));

                    if ($start <= 0 || $end <= 0 || $end < $start) {
                        return 'Informe um CEP inicial e final válidos.';
                    }

                    $total = $end - $start + 1;
                    $delayMs = (int) config('services.location_sync.request_delay_ms');
                    $estimatedSeconds = (int) ($total * $delayMs / 1000);

                    return 'Total de '.number_format($total, 0, ',', '.').' CEPs nesta faixa. Estimativa aproximada (só considera o intervalo configurado entre chamadas, não a latência real da rede): ~'.self::humanizeDuration($estimatedSeconds).'.';
                }),
        ];
    }

    private static function humanizeDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "{$minutes}min";
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return "{$hours}h";
        }

        $days = intdiv($hours, 24);

        return "{$days} dia(s)";
    }
}

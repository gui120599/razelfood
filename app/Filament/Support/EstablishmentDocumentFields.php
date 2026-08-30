<?php

namespace App\Filament\Support;

use App\Rules\ValidCnpj;
use App\Services\Address\IbgeService;
use App\Services\Address\ViaCepClient;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * CNPJ + endereço do estabelecimento, compartilhado entre o painel central
 * (TenantForm) e o painel do tenant (EstablishmentSettings) — mesma seção
 * nos dois lugares, para não divergir rótulo/máscara/validação.
 */
final class EstablishmentDocumentFields
{
    /**
     * Nomes dos campos gravados no model Tenant — usado pelo mount() do
     * EstablishmentSettings para popular o form.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['cnpj', 'zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state'];
    }

    public static function section(): Section
    {
        return Section::make('Endereço e documento')
            ->description('CNPJ e endereço do estabelecimento. Opcionais — usados em documentos e no contato.')
            ->columns(6)
            ->schema([
                InputMasks::cnpj(TextInput::make('cnpj')->label('CNPJ'))
                    ->rule(new ValidCnpj)
                    ->maxLength(18)
                    ->columnSpan(3),
                InputMasks::cep(TextInput::make('zip_code')->label('CEP'))
                    ->maxLength(9)
                    ->helperText('Ao sair do campo, busca o endereço automaticamente (ViaCEP).')
                    ->live(onBlur: true)
                    ->afterStateUpdated(self::fillAddressFromCep(...))
                    ->columnSpan(3),
                TextInput::make('street')
                    ->label('Logradouro')
                    ->maxLength(255)
                    ->columnSpan(4),
                TextInput::make('number')
                    ->label('Número')
                    ->maxLength(20)
                    ->columnSpan(2),
                TextInput::make('complement')
                    ->label('Complemento')
                    ->maxLength(255)
                    ->columnSpan(3),
                TextInput::make('neighborhood')
                    ->label('Bairro')
                    ->maxLength(255)
                    ->columnSpan(3),
                Select::make('state')
                    ->label('UF')
                    ->options(fn (): array => collect(app(IbgeService::class)->states())
                        ->mapWithKeys(fn (array $state): array => [$state['uf'] => "{$state['name']} ({$state['uf']})"])
                        ->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('city', null))
                    ->columnSpan(2),
                Select::make('city')
                    ->label('Cidade')
                    ->options(fn (Get $get): array => filled($get('state'))
                        ? collect(app(IbgeService::class)->citiesOf($get('state')))
                            ->mapWithKeys(fn (array $city): array => [$city['name'] => $city['name']])
                            ->all()
                        : [])
                    ->searchable()
                    // Sempre gravar: quando a UF está preenchida o campo fica
                    // habilitado; ->disabled() sozinho tira o valor do dehydrate.
                    ->dehydrated()
                    ->disabled(fn (Get $get): bool => blank($get('state')))
                    ->helperText('Selecione a UF primeiro.')
                    ->columnSpan(4),
            ]);
    }

    /**
     * Preenche logradouro/bairro/UF/cidade a partir do CEP via ViaCEP.
     * Auxiliar (RN-33): CEP inválido, não encontrado ou serviço fora do ar
     * apenas notifica — nunca bloqueia nem apaga o que já foi digitado.
     * A UF é setada antes da cidade para que o Select de cidade já tenha
     * a lista do IBGE correspondente ao aplicar o valor.
     */
    public static function fillAddressFromCep(?string $state, Set $set): void
    {
        $cep = preg_replace('/\D+/', '', (string) $state);

        if (strlen($cep) !== 8) {
            return;
        }

        $address = app(ViaCepClient::class)->lookup($cep);

        if ($address === null) {
            Notification::make()
                ->title('CEP não encontrado')
                ->body('Preencha o endereço manualmente.')
                ->warning()
                ->send();

            return;
        }

        foreach (['street', 'neighborhood', 'state', 'city'] as $field) {
            if (! empty($address[$field])) {
                $set($field, $address[$field]);
            }
        }
    }
}

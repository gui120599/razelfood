<?php

namespace App\Filament\Tenant\Resources\Clients\Schemas;

use App\Enums\PersonType;
use App\Filament\Support\InputMasks;
use App\Filament\Support\ProductFiscalFields;
use App\Rules\ValidCnpj;
use App\Rules\ValidCpf;
use App\Rules\ValidStateRegistration;
use App\Services\Address\ViaCepClient;
use App\Support\Fiscal\StateRegistration;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados do cliente')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        InputMasks::phone(TextInput::make('phone')->label('Telefone'))
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->helperText('Usado pelo cardápio público (RN-01) para reconhecer o cliente em pedidos futuros pelo mesmo telefone.'),
                        InputMasks::cpf(TextInput::make('cpf')->label('CPF'))
                            ->rule(new ValidCpf)
                            ->maxLength(14),
                    ]),
                Section::make('Dados fiscais')
                    ->description('Comprador da NFC-e. Pessoa física usa o CPF acima; pessoa jurídica, CNPJ e Inscrição Estadual.')
                    ->columns(2)
                    ->visible(fn (): bool => ProductFiscalFields::tenantUsesFiscal())
                    ->schema([
                        Select::make('person_type')
                            ->label('Tipo de pessoa')
                            ->options(collect(PersonType::cases())->mapWithKeys(
                                fn (PersonType $type): array => [$type->value => $type->label()]
                            )->all())
                            ->default(PersonType::Individual->value)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                // Campo oculto não é desidratado: trocar para pessoa física limpa os dados de PJ.
                                if ($state !== PersonType::Company->value) {
                                    $set('cnpj', null);
                                    $set('state_registration', null);
                                }
                            }),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        InputMasks::cnpj(TextInput::make('cnpj')->label('CNPJ'))
                            ->rule(new ValidCnpj)
                            ->maxLength(18)
                            ->required(fn (Get $get): bool => $get('person_type') === PersonType::Company->value)
                            ->visible(fn (Get $get): bool => $get('person_type') === PersonType::Company->value),
                        TextInput::make('state_registration')
                            ->label('Inscrição Estadual')
                            ->rule(new ValidStateRegistration)
                            ->dehydrateStateUsing(fn (?string $state): ?string => StateRegistration::normalize($state))
                            ->maxLength(20)
                            ->helperText('Só números, ou ISENTO.')
                            ->visible(fn (Get $get): bool => $get('person_type') === PersonType::Company->value),
                    ]),
                Section::make('Endereço')
                    ->columns(3)
                    ->schema([
                        InputMasks::cep(TextInput::make('zip_code')->label('CEP'))
                            ->maxLength(9)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (blank($state)) {
                                    return;
                                }

                                $address = app(ViaCepClient::class)->lookup($state);

                                if ($address === null) {
                                    Notification::make()
                                        ->title('CEP não encontrado')
                                        ->body('Preencha o endereço manualmente.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                foreach (['street', 'neighborhood', 'city', 'state'] as $field) {
                                    if (filled($address[$field] ?? null)) {
                                        $set($field, $address[$field]);
                                    }
                                }
                            })
                            ->helperText('Preenche logradouro, bairro, cidade e UF automaticamente (RN-33). Os campos continuam editáveis manualmente.'),
                        TextInput::make('street')
                            ->label('Logradouro')
                            ->maxLength(255)
                            ->columnSpan(2),
                        TextInput::make('number')
                            ->label('Número')
                            ->maxLength(20),
                        TextInput::make('complement')
                            ->label('Complemento')
                            ->maxLength(255)
                            ->columnSpan(2),
                        TextInput::make('neighborhood')
                            ->label('Bairro')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->label('Cidade')
                            ->maxLength(255),
                        TextInput::make('state')
                            ->label('UF')
                            ->maxLength(2),
                    ]),
            ]);
    }
}

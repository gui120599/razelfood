<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\CentralRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados do usuário')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255)
                            ->helperText(fn (string $operation): string => $operation === 'create'
                                ? 'Enviada ao usuário fora do sistema (ex.: WhatsApp).'
                                : 'Deixe em branco para manter a senha atual.'),
                        Select::make('central_role')
                            ->label('Papel na plataforma')
                            ->options(collect(CentralRole::cases())->mapWithKeys(
                                fn (CentralRole $role) => [$role->value => $role->label()]
                            ))
                            ->required()
                            ->default(CentralRole::Support->value)
                            ->disabled(fn (?User $record): bool => UserResource::isSelf($record))
                            ->helperText(fn (?User $record): string => UserResource::isSelf($record)
                                ? 'Você não pode alterar o próprio papel.'
                                : 'Plataforma: acesso total. Suporte: tenants e localidades, sem planos/features.'),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Tenant\Resources\Users\Schemas;

use App\Support\CurrentTenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

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
                        Select::make('roleIds')
                            ->label('Papéis')
                            ->multiple()
                            ->dehydrated(false)
                            ->options(fn () => Role::query()
                                ->where(config('permission.column_names.team_foreign_key'), CurrentTenant::id())
                                ->pluck('name', 'id'))
                            ->helperText('Controla o que este usuário pode ver e fazer no painel.'),
                    ]),
            ]);
    }
}

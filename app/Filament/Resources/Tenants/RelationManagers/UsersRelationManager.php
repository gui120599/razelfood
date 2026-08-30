<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gestão dos usuários de um tenant pelo painel central (equipe Razel Tec).
 * Complementa o `UserResource` do próprio painel do tenant — útil para
 * onboarding e para destravar um tenant que ficou sem Admin.
 *
 * spatie/laravel-permission roda com "teams" (`team_foreign_key = tenant_id`).
 * O painel central não passa pelo middleware `IdentifyTenant`, então o team
 * id nunca é setado sozinho — este RM seta explicitamente no `booted()`
 * (roda a cada requisição Livewire) para que a leitura/escrita de papéis
 * caia no tenant certo. Ver .ai/rules/users.md.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Usuários';

    protected static string|BackedEnum|null $icon = 'heroicon-o-users';

    public function booted(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->getOwnerRecord()->getKey());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                    ->options(fn (): array => Role::query()
                        ->where(config('permission.column_names.team_foreign_key'), $this->getOwnerRecord()->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('Controla o que este usuário pode ver e fazer no painel do tenant.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('roles')
                    ->label('Papéis')
                    ->badge()
                    ->state(fn (User $record): array => $this->tenantRoleNames($record)),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): Model => $this->persistUser(new User, $data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, User $record): array {
                        $data['roleIds'] = $this->tenantRoleIds($record);
                        unset($data['password']);

                        return $data;
                    })
                    ->using(fn (User $record, array $data): Model => $this->persistUser($record, $data)),
                DeleteAction::make()
                    ->visible(fn (User $record): bool => ! $this->isLastAdmin($record)),
            ]);
    }

    /**
     * Cria ou atualiza o usuário e sincroniza os papéis no team do tenant
     * (o team id já foi setado em booted()).
     *
     * @param  array<string, mixed>  $data
     */
    private function persistUser(User $user, array $data): User
    {
        $roleIds = Arr::pull($data, 'roleIds', []) ?? [];

        return DB::transaction(function () use ($user, $data, $roleIds): User {
            $user->fill($data);
            $user->tenant_id = $this->getOwnerRecord()->getKey();
            $user->save();

            $user->syncRoles(
                Role::query()
                    ->where(config('permission.column_names.team_foreign_key'), $this->getOwnerRecord()->getKey())
                    ->whereIn('id', $roleIds)
                    ->get()
            );

            return $user;
        });
    }

    /**
     * IDs dos papéis do usuário DENTRO deste tenant — lidos direto do pivot
     * para não depender do escopo por team do relacionamento.
     *
     * @return array<int, int>
     */
    private function tenantRoleIds(User $user): array
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $user->getMorphClass())
            ->where(config('permission.column_names.model_morph_key'), $user->getKey())
            ->where(config('permission.column_names.team_foreign_key'), $this->getOwnerRecord()->getKey())
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function tenantRoleNames(User $user): array
    {
        $ids = $this->tenantRoleIds($user);

        return $ids === []
            ? []
            : Role::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    /**
     * Impede destravar um tenant sem querer: o último usuário com o papel
     * Admin daquele tenant não pode ser excluído por aqui.
     */
    private function isLastAdmin(User $user): bool
    {
        if (! in_array('Admin', $this->tenantRoleNames($user), true)) {
            return false;
        }

        $adminRoleId = Role::query()
            ->where(config('permission.column_names.team_foreign_key'), $this->getOwnerRecord()->getKey())
            ->where('name', 'Admin')
            ->value('id');

        $admins = DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $adminRoleId)
            ->where(config('permission.column_names.team_foreign_key'), $this->getOwnerRecord()->getKey())
            ->count();

        return $admins <= 1;
    }
}

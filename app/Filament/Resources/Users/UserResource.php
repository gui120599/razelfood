<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Gestão dos usuários internos da Razel Tec (equipe da plataforma,
 * `tenant_id` nulo) e do papel de cada um no painel central (RN-44).
 * Usuários dos restaurantes são geridos pelo Admin de cada tenant, no
 * painel do tenant — nunca aparecem aqui. Acesso restrito a "Plataforma"
 * via App\Policies\UserPolicy::before().
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Equipe';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * User não estende TenantScopedModel (é Authenticatable puro) — sem
     * global scope. O painel central só enxerga os usuários da própria
     * equipe interna.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('tenant_id');
    }

    /**
     * Guard contra auto-lockout: o gestor logado não exclui a si mesmo nem
     * rebaixa o próprio papel.
     */
    public static function isSelf(?User $record): bool
    {
        return $record !== null && $record->id === auth()->id();
    }
}

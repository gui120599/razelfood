<?php

namespace App\Filament\Tenant\Resources\Users;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Users\Pages\CreateUser;
use App\Filament\Tenant\Resources\Users\Pages\EditUser;
use App\Filament\Tenant\Resources\Users\Pages\ListUsers;
use App\Filament\Tenant\Resources\Users\Schemas\UserForm;
use App\Filament\Tenant\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    use GatedByFeature;

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
     * User não estende TenantScopedModel (é Authenticatable puro) — não tem
     * o global scope automático de tenant. Mesmo reforço manual que
     * RoleResource já faz, senão o Admin de um tenant veria/editaria
     * usuários de outros tenants.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', CurrentTenant::id());
    }

    public static function requiredFeature(): string
    {
        return FeatureKey::USUARIOS_PERMISSOES;
    }
}

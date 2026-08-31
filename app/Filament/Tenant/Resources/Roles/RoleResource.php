<?php

namespace App\Filament\Tenant\Resources\Roles;

use App\Filament\Tenant\Concerns\GatedByFeature;
use App\Filament\Tenant\Resources\Roles\Pages\CreateRole;
use App\Filament\Tenant\Resources\Roles\Pages\EditRole;
use App\Filament\Tenant\Resources\Roles\Pages\ListRoles;
use App\Filament\Tenant\Resources\Roles\Pages\ViewRole;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Estende o RoleResource do Shield só para corrigir o isolamento por tenant.
 *
 * O painel do tenant usa a tenancy nativa do Filament (`->tenant()`), mas ela
 * escopa um Resource procurando a relação de posse `tenant()` no MODEL — e
 * `Spatie\Permission\Models\Role` não tem essa relação (o vínculo com o
 * tenant é via `model_has_roles.tenant_id`/`roles.tenant_id`, coluna de
 * pivot do recurso "teams" do spatie/permission, não uma BelongsTo). Por
 * isso `$isScopedToTenant = false` (senão o Filament estoura LogicException
 * ao montar a query), e o filtro por tenant é feito à mão em
 * getEloquentQuery() usando o team id do spatie, que ApplyTenantScopes já
 * setou a partir de Filament::getTenant().
 */
class RoleResource extends ShieldRoleResource
{
    use GatedByFeature;

    protected static bool $isScopedToTenant = false;

    protected static string|UnitEnum|null $navigationGroup = 'Equipe';

    public static function requiredFeature(): string
    {
        return FeatureKey::USUARIOS_PERMISSOES;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(config('permission.column_names.team_foreign_key'), CurrentTenant::id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}

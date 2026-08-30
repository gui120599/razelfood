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
 * O RoleResource vendor conta com o mecanismo NATIVO de multi-tenancy do
 * Filament (Filament::getTenant(), via ->tenant() no Panel) pra escopar a
 * listagem de Roles. Este projeto usa tenancy por domínio, sem ->tenant()
 * — Filament::getTenant() é sempre null aqui, então a query do vendor
 * nunca filtra por tenant, e o Admin de um tenant veria/editaria/apagaria
 * roles de OUTROS tenants. getEloquentQuery() abaixo fecha esse vazamento.
 */
class RoleResource extends ShieldRoleResource
{
    use GatedByFeature;

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

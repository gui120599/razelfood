---
paths:
  - 'app/Filament/Tenant/Resources/Users/**'
---

# Users

## Não usar Select::relationship() do Filament para atribuir roles de spatie/permission com teams habilitado
Com `config/permission.php: 'teams' => true` e `team_foreign_key => 'tenant_id'`, `User::roles()` (do `HasRoles`) é uma `BelongsToMany` com `withPivot('tenant_id')`. Um `Select::make('roles')->relationship('roles', 'name')` do Filament chama `$record->roles()->sync($ids)` internamente, mas `sync()` não sabe preencher a coluna extra de pivot (`tenant_id`) em `model_has_roles` — as linhas ficam com `tenant_id` nulo, quebrando o isolamento por tenant que `HasRoles::roles()` depende via `wherePivot()`.

Padrão usado em `UserResource`/`CreateUser`/`EditUser`: campo solto `Select::make('roleIds')->multiple()->dehydrated(false)` (não é uma relationship nativa), populado no fill via `$record->roles->pluck('id')`, e persistido via `$record->syncRoles(Role::whereIn('id', $data['roleIds'])->get())` num hook `afterCreate()`/`afterSave()`. `syncRoles()` (método do próprio spatie/permission) já respeita o team id atual, setado globalmente pelo `IdentifyTenant` middleware em toda requisição do painel do tenant — não precisa chamar `PermissionRegistrar::setPermissionsTeamId()` manualmente dentro da Page.

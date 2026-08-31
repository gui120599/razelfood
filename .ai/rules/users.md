---
paths:
  - 'app/Filament/Tenant/Resources/Users/**'
---

# Users

## Não usar Select::relationship() do Filament para atribuir roles de spatie/permission com teams habilitado
Com `config/permission.php: 'teams' => true` e `team_foreign_key => 'tenant_id'`, `User::roles()` (do `HasRoles`) é uma `BelongsToMany` com `withPivot('tenant_id')`. Um `Select::make('roles')->relationship('roles', 'name')` do Filament chama `$record->roles()->sync($ids)` internamente, mas `sync()` não sabe preencher a coluna extra de pivot (`tenant_id`) em `model_has_roles` — as linhas ficam com `tenant_id` nulo, quebrando o isolamento por tenant que `HasRoles::roles()` depende via `wherePivot()`.

Padrão usado em `UserResource`/`CreateUser`/`EditUser`: campo solto `Select::make('roleIds')->multiple()->dehydrated(false)` (não é uma relationship nativa), populado no fill via `$record->roles->pluck('id')`, e persistido via `$record->syncRoles(...)` num hook `afterCreate()`/`afterSave()`. O hook filtra por `->where(config('permission.column_names.team_foreign_key'), CurrentTenant::id())->whereIn('id', $data['roleIds'])` — o `where` por tenant é defesa contra `roleIds` forjado com id de papel de outro tenant (o `Select` só oferece os do tenant atual, mas o payload é do cliente). `syncRoles()` respeita o team id atual, setado por `ApplyTenantScopes` (tenant middleware persistente do painel do tenant) em toda requisição — não precisa chamar `PermissionRegistrar::setPermissionsTeamId()` manualmente dentro da Page.

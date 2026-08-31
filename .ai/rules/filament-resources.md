---
paths:
  - 'app/Filament/Resources/**'
---

# Filament Resources

## Painel central não usa Shield — Resources novos precisam de policy à mão
`app/Filament/Resources/**` é o painel central (`CentralPanelProvider`), que NÃO registra `FilamentShieldPlugin`. Autorização de Resource central vem de policy escrita à mão gateada por `users.central_role` (enum `App\Enums\CentralRole`: Platform/Support):
- Model exclusivo do central (Tenant/Plan/Feature/LocationSync): usar a trait `App\Policies\Concerns\CentralPanelPolicy` + `pricingSensitive()` (true = só Platform).
- Model compartilhado com o painel tenant (ex.: `User`): NÃO usar a trait (ela sobrescreve viewAny..replicate e quebra o Resource do tenant). Adicionar só um `before()` na policy existente: se `$authUser->isCentralUser()` → `return $authUser->central_role === CentralRole::Platform`; senão `return null` (cai nos métodos Shield do painel tenant).
NUNCA rodar `shield:generate --panel=central` (gera policies Shield que trancam todo mundo, inclusive o super admin — já aconteceu). `shield:generate --panel=tenant --all` só vale pra Resources do painel do tenant.
Ex.: `app/Filament/Resources/Users/UserResource.php` + `app/Policies/UserPolicy.php::before()`.

## Papéis spatie (teams) num RelationManager do painel central: setar team id no booted()
Um RelationManager central que gerencia papéis spatie/permission de um tenant (ex.: `UsersRelationManager` em `TenantResource`) roda no painel central, que NÃO tem tenant middleware (`ApplyTenantScopes` só existe no painel do tenant), então o team id (`tenant_id`) nunca é setado sozinho e `syncRoles()`/`$user->roles` caem no team errado (ou vazio).

Padrão: `public function booted(): void { app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->getOwnerRecord()->getKey()); }` — `booted()` roda a cada request Livewire, depois da hidratação de `$this->ownerRecord`. Filament usa hooks de trait (`bootedInteractsWithTable`), então `booted()` do componente não colide.

Para LEITURA robusta dos papéis do usuário naquele tenant (coluna de tabela, fill de edit), consultar o pivot direto: `DB::table(config('permission.table_names.model_has_roles'))->where('model_id', $user->id)->where(config('permission.column_names.team_foreign_key'), $ownerId)`. Para ESCRITA, `syncRoles()` já usa o team setado no booted().

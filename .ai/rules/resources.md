---
paths:
  - 'app/Filament/Tenant/Resources/**/*Resource.php'
---

# Resources

## Gating por feature usa a trait GatedByFeature — sempre combinar com parent::canAccess()
Resources do painel do tenant que devem ser controlados pelo catálogo de features (RN-43) usam `App\Filament\Tenant\Concerns\GatedByFeature` e implementam `requiredFeature(): string` retornando uma constante de `App\Support\FeatureKey`. A trait sobrescreve `canAccess()` e `shouldRegisterNavigation()` — SEMPRE combinando com `parent::canAccess()`/`parent::shouldRegisterNavigation()` (não substituindo), senão o gate por feature acaba pulando a autorização por role/policy do Filament Shield que o Resource já tinha. Resources gateados hoje: CategoryResource/ProductResource/FlashPromotionResource (`cardapio_digital`), DeliveryOptionResource/DeliveryZoneResource/PaymentOptionResource (`configuracoes_estabelecimento`), OrderResource (`historico_pedidos`), ProductionLineResource (`linhas_producao`), RoleResource (`usuarios_permissoes`) — este último confirmado sem colisão porque `ShieldRoleResource` (vendor) não sobrescreve `canAccess()` diretamente, só `shouldRegisterNavigation()` via uma trait própria (`Essentials\HasNavigation`), que `parent::` alcança normalmente. Pages que usam `HasPageShield` (Kitchen, OrderSettings) NÃO usam esta trait — ver .ai/rules/pages.md.

## Rodar shield:generate --panel=tenant --all sempre que criar um Resource/Page novo
`SeedDefaultTenantRoles` dá à role Admin `Permission::all()` — mas isso só inclui o que já existe na tabela `permissions`. Um Resource/Page novo não gera permissões sozinho; sem rodar `vendor/bin/sail artisan shield:generate --panel=tenant --all --no-interaction` depois de criá-lo, a Admin de qualquer tenant fica sem acesso a ele (e tenants antigos ficam com a Admin travada em poucas permissões até alguém rodar `tenant:seed-roles` de novo pra cada um).

Bug real encontrado em 2026-08-21: depois de adicionar `ClientResource`/`UserResource`, ninguém tinha rodado `shield:generate` na tabela `permissions` recém-resetada — a Admin de um tenant existente (lazzopizza) tinha só 2 permissões (as customizadas `manage_order_status`/`mark_order_delivered`), travada fora de TODOS os Resources reais. Corrigido rodando `shield:generate --panel=tenant --all` e depois `app(SeedDefaultTenantRoles::class)($tenant)` de novo pra cada tenant existente.

**Nunca rodar `shield:generate --panel=central --all`** — o painel central não tem Shield/RBAC desenhado (nenhum papel, nenhuma permissão atribuída a ninguém). Rodar isso gera Policies (`TenantPolicy`, `PlanPolicy`, `FeaturePolicy`) que passam a exigir `$user->can('ViewAny:...')` via Gate — como ninguém no painel central tem permissões, isso tranca até o super admin (`tenant_id null`) fora do próprio painel central. Se isso acontecer, apagar os arquivos gerados em `app/Policies/{Tenant,Plan,Feature}Policy.php` restaura o comportamento padrão do Filament (sem Policy = libera por padrão, ver `Filament\get_authorization_response()`).

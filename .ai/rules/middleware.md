---
paths:
  - app/Http/Middleware/ResolveTenantFromPath.php
  - app/Http/Middleware/ApplyTenantScopes.php
---

# Middleware

## Tenancy é por PATH, não por subdomínio (migração de 2026-08-31)

O tenant NÃO é mais resolvido pelo `Host`/subdomínio. Dois caminhos, ambos convergindo em `CurrentTenant` setado:

- **Cardápio público** (`razelfood.com.br/{slug}/...`): `ResolveTenantFromPath` é o middleware do grupo `Route::prefix('{tenant}')` em `routes/web.php` (NÃO é mais middleware global — saiu do `prepend` em `bootstrap/app.php`). Lê `$request->route('tenant')`, resolve por `slug`, `abort(404)` se não achar / `abort(503)` se `status != Active`, e seta `CurrentTenant` + `URL::defaults(['tenant' => $slug])` + `PermissionRegistrar::setPermissionsTeamId()` + `app()->instance(Tenant::class)`. SEMPRE roda em contexto de tenant — não existe mais o ramo "rota central, segue sem tenant".
- **Painel do tenant** (`razelfood.com.br/painel/{slug}`): tenancy NATIVA do Filament (`->tenant(Tenant::class, slugAttribute: 'slug')` no `TenantPanelProvider`). O Filament resolve/valida o `{tenant}` (route-model binding + `User::canAccessTenant()`), e `ApplyTenantScopes` (tenant middleware persistente, `->tenantMiddleware([...], isPersistent: true)`) faz a ponte `Filament::getTenant()` → `CurrentTenant`/`URL::defaults`/spatie-teams. Roda também nas requisições Livewire AJAX do painel.
- **Painel central** (`razelfood.com.br/admin`): sem tenant middleware. `CurrentTenant::id()` fica `null` → `TenantScope` não filtra → super admin vê todos os tenants.

## Cache de tenant por slug sobrevive a restart do Docker (CACHE_STORE=database)
`ResolveTenantFromPath` guarda o tenant resolvido em `Cache::remember("tenant:slug:{slug}", ttl)`. Como `CACHE_STORE=database` (persistido na tabela `cache`, não em memória), reiniciar containers do Sail/Docker NÃO limpa essa entrada — ela só some no TTL (default 5 min) ou com `Cache::forget()`/`cache:clear` manual. Ao suspender/reativar um tenant manualmente para teste, o cardápio pode continuar bloqueado (503) por até o TTL mesmo com `status=active` no banco. Se isso acontecer: `Cache::forget("tenant:slug:{slug}")` ou `vendor/bin/sail artisan cache:clear`. Ao trocar o slug de um tenant pelo painel central, `EditTenant::changeSlug` já invalida as duas chaves (antigo e novo).

## `URL::defaults(['tenant' => ...])` é o ponto de costura da geração de URL
Como quase todo `route('menu.index')` / `route('order.tracking', ['order' => ...])` nas views e actions OMITE o parâmetro `tenant`, ele depende do `URL::defaults` setado no middleware. Testes que geram essas URLs fora de uma requisição precisam setar `URL::defaults(['tenant' => $slug])` no `setUp()` (ou disparar `$this->get("/{$slug}")` antes).

## `POST /livewire/update` NÃO passa por `ResolveTenantFromPath`
A rota do Livewire (`livewire/update`, middleware `web`) não está no grupo `Route::prefix('{tenant}')`. Os componentes Livewire públicos do cardápio (`app/Livewire/Menu.php`, `Checkout.php`, `OrderStatusTimeline.php`) restauram o contexto do tenant sozinhos via a trait `App\Livewire\Concerns\EstablishesTenantContext` (slug numa property checksummed + hook `booted`). Ver [[app-livewire]]. O painel do Filament não tem esse problema — `Livewire::addPersistentMiddleware` do próprio Filament + `ApplyTenantScopes`.

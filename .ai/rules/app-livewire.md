---
paths:
  - 'app/Livewire/{Menu.php,Checkout.php,OrderStatusTimeline.php,Concerns/EstablishesTenantContext.php}'
---

# App Livewire

## Componente Livewire público precisa da trait EstablishesTenantContext
Com a tenancy por path, `POST /livewire/update` NÃO passa pelo grupo `Route::prefix('{tenant}')` de routes/web.php (a rota é `livewire/update` pura, middleware `web` só). Então `ResolveTenantFromPath` não roda nas requisições AJAX dos componentes públicos do cardápio — `CurrentTenant` fica null a partir do 1º `wire:*`, o TenantScope para de filtrar e `route('checkout.index')`/`route('menu.index')` estouram UrlGenerationException (falta `{tenant}`).

Todo componente em `app/Livewire/` servido nas rotas públicas do tenant (Menu, Checkout, OrderStatusTimeline) usa `App\Livewire\Concerns\EstablishesTenantContext`: guarda o slug numa property pública `$tenantSlug` (protegida pelo checksum do snapshot do Livewire 3) e re-resolve o tenant no hook `bootedEstablishesTenantContext()` a cada requisição. Filament não tem esse problema — usa `Livewire::addPersistentMiddleware`.

Não vale para componentes Livewire embutidos no painel do Filament (ApplyTenantScopes cuida disso lá).

---
paths:
  - 'app/Livewire/{Menu.php,Checkout.php,OrderStatusTimeline.php,Concerns/EstablishesTenantContext.php}'
---

# App Livewire

## Componente Livewire público precisa da trait EstablishesTenantContext
Com a tenancy por path, `POST /livewire/update` NÃO passa pelo grupo `Route::prefix('{tenant}')` de routes/web.php (a rota é `livewire/update` pura, middleware `web` só). Então `ResolveTenantFromPath` não roda nas requisições AJAX dos componentes públicos do cardápio — `CurrentTenant` fica null a partir do 1º `wire:*`, o TenantScope para de filtrar e `route('checkout.index')`/`route('menu.index')` estouram UrlGenerationException (falta `{tenant}`).

Todo componente em `app/Livewire/` servido nas rotas públicas do tenant (Menu, Checkout, OrderStatusTimeline) usa `App\Livewire\Concerns\EstablishesTenantContext`: guarda o slug numa property pública `$tenantSlug` (protegida pelo checksum do snapshot do Livewire 3) e re-resolve o tenant no hook `bootedEstablishesTenantContext()` a cada requisição. Filament não tem esse problema — usa `Livewire::addPersistentMiddleware`.

Não vale para componentes Livewire embutidos no painel do Filament (ApplyTenantScopes cuida disso lá).

## Checkout: endereço de entrega é progressivo (CEP primeiro) + modo restrito por config
Set/2026. Quando a opção escolhida tem `requires_address`:
- Só o campo **CEP** + botão "Não sei meu CEP" aparecem no início. `Checkout::$addressUnlocked` (bool) libera o resto — vira `true` em `lookupCep()` (achou ou não), `revealManualAddress()` (o botão) e `lookupClient()` quando o cliente já tem endereço salvo. `submit()` tem um guard: `requiresAddress && ! $addressUnlocked` → erro "comece pelo CEP" + `dispatch('checkout-validation-failed', field: 'zipCode')` (o CEP tem `data-field="zipCode"`), antes de qualquer regra.
- **Modo restrito** = `Checkout::addressIsRestricted` (computed) = `! ($tenant->allow_free_form_address ?? true) && $tenant->deliveryZones()->exists()`. O `?? true` é essencial: em teste `Tenant::create()` não traz o default do banco, e sem ele todo tenant com setor cairia no modo restrito. Config nova `tenants.allow_free_form_address` (bool default true) editada em `OrderSettings` → seção "Endereço no checkout".
- Restrito: estado (`wire:model.live="state"`, UF), cidade (`wire:model.live="cityId"`, id do catálogo `App\Models\City`) e bairro viram `<select>`. Opções: `servedStates`/`servedCities` (cidades que têm bairro em algum setor, via `DeliveryZoneNeighborhood::whereNotNull('city_id')`); `neighborhoodOptions` = **todos** os bairros do catálogo global daquela cidade (`Neighborhood::where('city_id', ...)`), não só os atendidos — o casamento com setor / a taxa de bairro não mapeado / o bloqueio acontecem depois em `ResolveDeliveryFee` no `submit()`. `updatedState()` zera `cityId/city/neighborhood`; `updatedCityId()` deriva `city` (nome) e `state` (UF).
- Livre (default) ou tenant sem setores: os `@else` do blade mantêm os `<input>` de texto atuais; comportamento antigo preservado, só com o passo "CEP primeiro".
- `submit()` monta `$addressRules` na ORDEM VISUAL (restrito: state→city→neighborhood→street; livre: street→neighborhood) pra `checkout-validation-failed` focar o 1º campo pendente que o cliente está vendo. `ResolveDeliveryFee` NÃO mudou (casa por string normalizada).
- Testes: `tests/Feature/CheckoutRestrictedAddressTest.php` (modo restrito), `CheckoutAddressTest.php` (livre — os testes de submit chamam `->call('revealManualAddress')` antes), `CheckoutValidationFocusTest.php`.

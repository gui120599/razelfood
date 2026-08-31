---
paths:
  - routes/web.php
---

# Routes

## Tenancy por path: grupo `Route::prefix('{tenant}')` com constraint de slug reservado
As rotas públicas do tenant (cardápio, checkout, acompanhamento, comanda, entrega, relatórios imprimíveis) vivem sob `Route::prefix('{tenant}')->middleware(ResolveTenantFromPath::class)->where(['tenant' => <regex>])`. O regex é montado de `config('tenancy.reserved_slugs')` com negative lookahead — `/admin`, `/painel/...`, `/livewire/...`, `/up` etc. NÃO casam o grupo `{tenant}` e caem nas rotas de sistema/painel (registradas depois pelos PanelProviders / providers de pacote). Ao adicionar uma rota de primeiro nível nova fora do grupo `{tenant}`, adicione o segmento em `reserved_slugs` também.

A rota `landing` (`/`) e qualquer rota de sistema têm que ser registradas ANTES do grupo `{tenant}` (o grupo é praticamente um catch-all do primeiro segmento).

## `URL::defaults(['tenant' => ...])` — não passar `tenant` explícito em `route()`
`ResolveTenantFromPath` (e `ApplyTenantScopes` no painel) setam `URL::defaults(['tenant' => $slug])`. `route('menu.index')`, `route('checkout.index')`, `route('order.tracking', ['order' => $token])` etc. devem OMITIR o `tenant` — ele entra pelo default. Só passe explícito quando gerar a URL de um tenant diferente do contexto atual (ex.: item de navegação "Ver cardápio" no `TenantPanelProvider`).

## Route-model binding voltou a funcionar (era quebrado com `Route::domain('{tenant}...')`)
Antes, dentro de `Route::domain('{tenant}.'.base_domain)`, a injeção de parâmetro por nome/type-hint casava o wildcard de domínio em vez do parâmetro de URI. Com `Route::prefix('{tenant}')` isso não acontece mais. Os controllers de pedido (`OrderTrackingController`, `OrderTicketController`, `DeliveryConfirmationController`, `OrdersReportPrintController`) ainda leem `$request->route('order')` manualmente por segurança/consistência — não é obrigatório, mas manter é mais explícito (a resolução passa pelo `TenantScope` via `Order::findOrFail`).

---
paths:
  - 'app/{Models/User.php,Providers/AppServiceProvider.php,Providers/Filament/*.php}'
---

# Models Filament

## Auth entre painéis: cookie único + super admin "Plataforma" acessa qualquer tenant
Desde a tenancy por path (2026-08-31) central (/admin) e tenant (/painel/{slug}) compartilham o cookie de sessão (guard `web`, mesmo domínio). Não há guard por painel — mudar isso quebraria ~26 testes de painel que usam `actingAs($user)` sem guard e todo `auth()->user()` em código do painel.

- `User::canAccessPanel('tenant')` = `tenant_id !== null || hasCentralRole(Platform)`. `canAccessTenant()` e `getTenants()` também liberam o Platform para qualquer tenant. Sem isso, o super admin logado em /admin levava 403 ao abrir /painel/{slug}.
- `AppServiceProvider::boot()` tem um `Gate::before` que retorna `true` para `hasCentralRole(CentralRole::Platform)` (e `null` para os demais) — curto-circuita a autorização do Filament Shield no painel do tenant para o super admin. Não afeta central (Platform já passava pelas policies) nem Support (cai no fluxo normal).
- Isolamento de dados continua por contexto: o super admin em /painel/tenantA só vê dados de A (TenantScope + escopo nativo do Filament via ApplyTenantScopes). Para ver B, abre /painel/tenantB.
- Usuário comum de tenant em /admin → 403 (correto). Um tenant e o central não coexistem no mesmo browser a não ser que o central seja Platform.
- Os controllers das rotas públicas do painel que checam tenant à mão (OrderTicketController, OrdersReportPrintController, DeliveriesReportPrintController — comanda e relatórios imprimíveis, abertos em nova aba a partir do painel) usam `Auth::user()->canOperateInCurrentTenant()` em vez de `tenant_id === CurrentTenant::id()`, senão o super admin Plataforma levava 403 ao imprimir. O `->can('...')` de permissão nesses controllers já passa pelo Gate::before.

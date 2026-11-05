---
paths:
  - 'app/{Support/Tenancy/**,Jobs/**,Console/Concerns/**,Console/Commands/**}'
---

# Concerns Commands

## Job/command fora de request precisa de TenantContext; job middleware só roda em job enfileirável
Sem CurrentTenant o TenantScope vira no-op e o código enxerga todos os tenants. Job: trait `App\Jobs\Concerns\RunsInTenantContext` + `public readonly int $tenantId` no construtor (id, nunca o model). Command: trait `RunsForEachTenant` (tenants ativos com a feature, falha isolada por tenant). `TenantContext::run()` RESTAURA o contexto anterior no finally (= forget() quando não havia).
Armadilhas: (1) job middleware só roda via pipeline da fila — o job precisa de ShouldQueue + Queueable; `dispatch_now`/dispatchNow de job não enfileirável ignora o middleware e roda sem tenant. (2) Se a classe define o próprio `middleware()`, ele vence o da trait: inclua `...$this->tenantContextMiddleware()`. (3) `failed()` e listeners rodam fora do middleware: use `$this->inTenantContext(fn)`. (4) Contexto de log: `Log::shareContext` (vale para todos os canais), nunca `withContext`. (5) CurrentTenant é estático: testes que assertam "nulo" devem chamar CurrentTenant::forget() no setUp.

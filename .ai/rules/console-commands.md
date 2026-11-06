---
paths:
  - '{app/Actions/Sales/SyncPdvActivation.php,app/Support/Sales/PdvCutoff.php,app/Jobs/CancelUnpaidSalesJob.php,app/Console/Commands/SyncPdvActivationCommand.php}'
---

# Console Commands

## PDV: data de corte `pdv_enabled_at` e cancelamento só de vendas sem pagamento
`tenants.pdv_enabled_at` acompanha a feature `pdv`: gravada por observers (Tenant plan_id, TenantFeatureOverride) e pelo comando `sales:sync-pdv-activation` (a cada 5 min — pega edição de features de plano). Pedidos anteriores à data nunca entram em "Contas a fechar" nem em FinalizeSale; só pedidos de "Aceito" em diante. Leia SEMPRE do banco via `PdvCutoff::for` (o Tenant em cache `tenant:slug:*` fica defasado); sem data = PDV inativo. Perder a feature limpa a data (reativar começa data nova) e despacha `CancelUnpaidSalesJob`, que revalida o estado ao rodar, é único por tenant, cancela só vendas Finalizadas SEM pagamento e respeita `SaleCancellationGuard` (gancho fiscal da Fase 1.2). Vendas pagas ficam Finalizadas e os pedidos delas continuam bloqueados até religar o PDV.

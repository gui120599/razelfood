---
paths:
  - '{app/Filament/Tenant/Pages/Sales/**,app/Filament/Tenant/Resources/Sales/**,app/Policies/SalePolicy.php}'
---

# Policies

## Telas de venda: gate por feature `pdv` + `manage_sales` (sem HasPageShield); shield:generate sem sobrescrever policy
`PendingAccounts` e `CloseAccount` (Pages) usam `canAccess()` = feature `pdv` + `can('manage_sales')`, sem HasPageShield (não geram permissão de página). `SaleResource` usa GatedByFeature + `SalePolicy` (ViewAny/View:Sale; criar/editar/excluir = false) e a ação "Cancelar venda" exige `manage_sales`. Ao criar Resource novo: `shield:generate --panel=tenant --all --ignore-existing-policies` (sem o flag, o Shield SOBRESCREVE a SalePolicy restritiva) e `tenant:seed-roles {slug}` por tenant (Gerente e Caixa recebem manage_sales + leitura de vendas). `CloseAccount` tem `#[Locked]` em `orderIds`/`finalized` (adulteração e duplo clique). O resumo de troco na tela é só preview; vale a regra de FinalizeSale.

---
paths:
  - '{app/Filament/Tenant/Pages/Sales/**,app/Filament/Tenant/Livewire/**,app/Filament/Tenant/Resources/Products/**,routes/web.php}'
---

# Products

## CloseAccount/PendingAccounts foram APAGADOS (URLs antigas redirecionam por rota); `show_in_waiter_menu` manda nas telas internas
As telas antigas de venda não existem mais (as regras policies.md/sales.md/pages-sales.md ainda as citam: leia como NewSale). As URLs `painel/{tenant}/fechar-conta` e `/contas-a-fechar` redirecionam por rota (`sales.legacy_redirect` em routes/web.php, fora do grupo {tenant}) para `vendas/operar`, levando `?orders=`. Produto tem `show_in_waiter_menu` (cardápio do garçom, uso interno, como `produto_cardapio_garcom` do Pizzaria-App; default true, backfill = is_visible): as telas INTERNAS (Nova Venda: SaleProductSelector; Atender Pedido: ProductCatalogSelector e FlavorPickerModal) filtram por ele e NÃO por `is_visible`, que continua só do cardápio público (Menu). O futuro painel do garçom usa o mesmo campo. Categoria ainda não tem o equivalente (o Pizzaria tem `categoria_cardapio_garcom`).

---
paths:
  - '{app/Filament/Tenant/Pages/Sales/NewSale.php,app/Filament/Tenant/Livewire/Sales/**,resources/views/filament/tenant/pages/sales/new-sale*,resources/views/filament/tenant/livewire/sales/**,app/Support/Sales/SaleCartLines.php}'
---

# Sales

## Nova Venda (cópia do OperarVenda): vínculos traduzidos, nada de estado só na tela, lista de exclusões da cópia
As views são COPIADAS do Pizzaria-App por fatias (markup idêntico) e só os vínculos mudam (venda→sale, aba 'orders'|'products', métodos em inglês). Fora da cópia, por não existirem no RazelFood: Mesas, Pendentes/fiado, cortesia, desconto por item, dividir item, Stone ("Como receber"/maquininha), carteira de influenciador, taxa de serviço, alteração de venda finalizada, "Troco em". Cada ação da página grava direto pelas Actions do rascunho (nunca estado só em Livewire); SaleException vira toast (guard()) ou erro do modal (pagamento) e `#[Locked] saleId`. O rascunho nasce no 1º lançamento (ensureSale) e é descartado ao sair pelo sendBeacon (rota `sales.discard_empty`, auth + feature + manage_sales, só apaga vazio) e pelo comando. Pagamento: "Valor recebido" é a parte que cobre a conta e "Pago pelo cliente" (só dinheiro) é o valor ENTREGUE gravado em amount; o troco é do servidor ao finalizar. Pedido declarou pagamento → banner "Forma indicada no pedido" + pré-seleção (SuggestedPayment). Busca de cliente: orWhere SEMPRE agrupado em where() (senão o TenantScope vaza). CloseAccount/PendingAccounts só redirecionam (`?orders=` leva os pedidos) até serem apagadas com aprovação. Livewire: não use `once()` com closure que captura $item; computeds precisam de unset em refreshState().

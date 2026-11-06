---
paths:
  - '{app/Actions/Sales/**,app/Actions/Orders/CancelOrder.php,app/Actions/Orders/UpdateOrderFromCart.php,app/Models/Order.php,app/Models/OrderItem.php}'
---

# Models Models

## Venda (sales): protocolo de lock e invariante orders.sale_id × order_items.sale_id
Toda mudança em pedido/itens TRAVA O PEDIDO PRIMEIRO (`Order::lockForUpdate()->findOrFail`) e reavalia `sale_id`/status sobre a linha travada — nunca confie no model recebido (pode estar defasado; num retry da transação a guarda externa não vale). Ordem de lock: orders -> products (ledger) -> tenants (AllocateSaleNumber). NUNCA lock/UPDATE de `order_items` por `order_id` (sem tenant_id: trava o gap do índice de todos os tenants); leia sem lock e atualize por PK (`whereKey`). `orders.sale_id` e `order_items.sale_id` só são escritos por FinalizeSale/CancelSale (por isso fora do $fillable). Dinheiro em centavos inteiros (`App\Support\Sales\Money`), troco calculado no servidor e só de forma `is_cash`. Pedido vendido não é cancelado nem editado (cancele a venda). `sale_orders` guarda o snapshot dos pedidos cobrados e sobrevive ao cancelamento. grand_total do pedido = itens + taxa (o desconto é informativo).

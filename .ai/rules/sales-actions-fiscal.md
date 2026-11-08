---
paths:
  - '{app/Actions/Sales/**,app/Support/Sales/**,app/Actions/Fiscal/BuildNfcePayload.php}'
---

# Sales Actions Fiscal

## Desconto em reais por linha (Desc. R$): onde mora, como soma e como sai na NFC-e
Item avulso guarda `sale_items.discount_amount` (sale_items.total segue BRUTO = preço × quantidade); item de pedido guarda em `sale_order_item_discounts` (sale_id + order_item_id, sem mexer em order_items), apagado ao devolver o item (DetachOrderItems); CancelSale deixa como histórico da venda cancelada e a venda seguinte NÃO herda. Regras (origem OperarVenda): ≤ valor da linha, escala com a quantidade preservando o desconto POR UNIDADE (LineDiscount::scale; arredondar ida e volta deriva ±1 centavo, igual à origem), soma-se ao percentual que incide sobre o LÍQUIDO (itens − descontos por linha). `sales.manual_discount_total` = descontos por linha + percentual. NFC-e: discountAmount da linha = own_discount_cents (limitado ao bruto) + parte do que resta do desconto total, rateada por CentAllocator::proportional sobre o valor líquido da linha (linha toda descontada fica com peso 0). SetLineDiscount trava o pedido (lido fora da transação) e depois a venda; campo de tela não cria rascunho (usa $this->sale). Quantidade na tela: botões −/+ em todas as linhas; item de pedido só avisa (quantidade é a do pedido; sem 'Dividir').

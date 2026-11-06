---
paths:
  - 'app/{Actions/Fiscal/BuildNfcePayload.php,Support/Fiscal/BuyerResolver.php,Support/Fiscal/FlavorFraction.php}'
---

# Fiscal Fiscal

## Payload da NFC-e segue o formato validado do Pizzaria-App; só `id` diverge de propósito
O payload NFE.io já está validado em produção no Pizzaria-App (NfeIoService::montarPayload/montarItens/montarComprador): NÃO mude o FORMATO sem prova. Regras copiadas: `buyer` sempre presente (null sem comprador); `federalTaxNumber` do comprador e do cartão como TEXTO (zero à esquerda); PJ leva `stateTaxNumber`; documento do outro tipo vale se o do tipo faltar; `codeGTIN` null sem GTIN (a NFE.io preenche); `cest` sempre presente (null); `card` com as 4 chaves (federalTaxNumber, flag, authorization, integrationPaymentType='notIntegrated') para cartão E Pix; `totalIndicator` = valor cobrado do item > 0 (sem contar frete); description = categoria + produto (ou frações "1/2 A / 1/2 B") + " Adic. nome", ASCII, máx. 120. Divergência deliberada: `id` = id do documento fiscal (o número da venda repete entre tenants e a conta NFE.io única, D-03, ainda está em aberto). Frações vêm de FlavorQuantityOption (categoria ou pai, withTrashed, sem TenantScope + where tenant_id), senão divisão igual. O "Por produto" do relatório usa `fiscal_document_items.product_name` (a description agora fragmentaria).

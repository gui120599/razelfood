---
paths:
  - 'app/Filament/Tenant/Pages/Sales/**'
---

# Pages Sales

## NewSale tem o mesmo gate das telas de venda; retry fiscal exige emit_fiscal_document; ?orders= redireciona para o rascunho
NewSale usa o mesmo gate de CloseAccount/PendingAccounts (feature `pdv` + `manage_sales`, sem HasPageShield; o Filament reavalia canAccess a cada request Livewire). Reenviar NFC-e pela tela exige TAMBÉM `emit_fiscal_document` (abort 403 em retryFiscalIssue + @can no botão) e, se o envio foi ambíguo (FailedToSend/Processing sem provider_id e com sent_at), avisa e pede confirmação para não duplicar a nota. O modal fiscal nunca gira para sempre: conta polls (máx. 40 × 3 s), Reserved/FailedToSend/Failed/Cancelled/Processing parado viram erro com a mensagem do documento. `?orders=` (URL antiga) cria o rascunho e REDIRECIONA para `vendas/operar/{sale}` (F5 retoma em vez de relançar e deixar rascunho preso). O sendBeacon lê `$wire.saleId` na hora do `beforeunload` (o x-data só é avaliado uma vez, então a URL não pode ser montada no render). `orderIsLaunched` precisa de `saleId !== null` (null === null marcava todo pedido). Em teste de Livewire, mount que chama redirect() não deixa o componente utilizável: monte o rascunho por Action e abra `Livewire::test(NewSale::class, ['sale' => $id])`.

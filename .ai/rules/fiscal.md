---
paths:
  - 'app/{Actions/Fiscal/**,Services/Fiscal/**,Models/FiscalDocument.php,Models/FiscalNumberSequence.php}'
  - 'app/{Actions/Fiscal/BuildNfcePayload.php,Enums/CardBrand.php,Support/Fiscal/BuyerResolver.php}'
---

# Fiscal

## NFC-e: documento vivo ANTES do HTTP, reserva sem FOR UPDATE em sale_id e sequência criada fora da transação
IssueFiscalDocument: reserva (transação curta) -> monta payload -> markSending (sob lock da venda: revalida Finalized e põe o documento em Processing com sent_at) -> HTTP fora de transação. Sem isso, CancelSale (guard só enxerga status vivo) cancela a venda durante o POST. Reserva: lock só na linha da venda; NUNCA lockForUpdate em fiscal_documents por sale_id (gap lock deadlocka vendas novas do tenant) e criar a linha de fiscal_number_sequences com insertOrIgnore FORA da transação (dentro, o lock compartilhado + FOR UPDATE deadlocka). Falha ambígua (timeout/5xx/resposta sem id) mantém sent_at e a UI avisa antes do reenvio; 4xx zera sent_at. Processing sem provider_id há >10 min deixa de ser vivo (FiscalDocument::isLive/scopeLive). Consulta tardia nunca rebaixa Issued/Cancelled para Processing. Lock de cache da venda deve durar mais que o timeout do HTTP. provider_id e nfeio_company_id são únicos (testes com 2 tenants/2 notas precisam de ids distintos). Credencial: DecryptException vira NfeIoException; nunca em log/mensagem.

## O que o Pizzaria-App provou em produção (570 NFC-e emitidas) e o que NÃO foi provado
Levantado em 2026-11-06 lendo o banco do Pizzaria-App (somente leitura; subir só o mysql: `FORWARD_DB_PORT=3307 docker compose up -d mysql` no Pizzaria-App e rodar PHP do host com DB_HOST=127.0.0.1 DB_PORT=3307). PROVADO: métodos cash/creditCard/debitCard/InstantPayment; card de Pix com as 4 chaves nulas; flags `Visa`, `MasterCard`, `Elo` (flag null também passou); troco (44 pagamentos); codeGTIN null; cest string; buyer null (569 de 570); multi-sabor "Categoria 1/2 A / 1/2 B" (317 itens); CSOSN 102 e 500 com tax {icms origin/csosn/baseTax 0/amount 0/rate 0}; totalIndicator sempre true (bool de "0.00" é true). Valores monetários lá vão como TEXTO ("40.00") e aqui como número: igual em efeito, só a homologação confirma. SEM PROVA: comprador identificado (1 amostra, PF), IE do PJ, CSOSN diferente de 102/500 (bloco totalTax/baseTaxModality 3), respostas de perguntas, item com GTIN, American Express/Sorocred/Alelo/HiperCard/Outros (o Pizzaria envia o rótulo, ex.: "American Express" com espaço e "Outros"; aqui `AmericanExpress`/`Other` pela documentação), nota em ambiente de homologação.

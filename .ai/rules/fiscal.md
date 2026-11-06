---
paths:
  - 'app/{Actions/Fiscal/**,Services/Fiscal/**,Models/FiscalDocument.php,Models/FiscalNumberSequence.php}'
---

# Fiscal

## NFC-e: documento vivo ANTES do HTTP, reserva sem FOR UPDATE em sale_id e sequência criada fora da transação
IssueFiscalDocument: reserva (transação curta) -> monta payload -> markSending (sob lock da venda: revalida Finalized e põe o documento em Processing com sent_at) -> HTTP fora de transação. Sem isso, CancelSale (guard só enxerga status vivo) cancela a venda durante o POST. Reserva: lock só na linha da venda; NUNCA lockForUpdate em fiscal_documents por sale_id (gap lock deadlocka vendas novas do tenant) e criar a linha de fiscal_number_sequences com insertOrIgnore FORA da transação (dentro, o lock compartilhado + FOR UPDATE deadlocka). Falha ambígua (timeout/5xx/resposta sem id) mantém sent_at e a UI avisa antes do reenvio; 4xx zera sent_at. Processing sem provider_id há >10 min deixa de ser vivo (FiscalDocument::isLive/scopeLive). Consulta tardia nunca rebaixa Issued/Cancelled para Processing. Lock de cache da venda deve durar mais que o timeout do HTTP. provider_id e nfeio_company_id são únicos (testes com 2 tenants/2 notas precisam de ids distintos). Credencial: DecryptException vira NfeIoException; nunca em log/mensagem.

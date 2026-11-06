---
paths:
  - 'app/{Support/Fiscal/FiscalReportQuery.php,Filament/Tenant/Pages/FiscalReport.php,Http/Controllers/Reports/FiscalReport*.php,Models/FiscalDocumentItem.php}'
---

# Controllers Reports

## Relatório fiscal: base única (notas válidas), snapshot de itens na emissão, sem DELETE por chave não única, CSV seguro
FiscalReportQuery é a fonte de página, CSV e impressão e NÃO depende do tenant atual (filtra tenant_id em toda consulta, inclusive DB::table). "Notas válidas" = FiscalDocumentStatus::standingValues() (Issued, CancelRequested, CancelFailed), pela data authorized_at (hora em que o sistema registrou); o contador da central usa a mesma regra, só produção e sem soft-deleted (withoutGlobalScope(TenantScope), nunca withoutGlobalScopes). Os agrupamentos por item leem o snapshot fiscal_document_items gravado em IssueFiscalDocument::markSending (mesma transação do status Processing). Nota sem snapshot (emitida antes) entra na lista e na forma de pagamento mas não nos agrupamentos: a tela, o CSV e a impressão avisam (notesWithoutItems). Reenvio: ler ids sem lock e apagar por PK, e só se houver (DELETE por fiscal_document_id sem linhas toma gap lock no fim do índice e deadlocka emissões simultâneas); inserir em lote. Forma de pagamento = JOIN com fiscal_documents (nunca whereIn com milhares de ids: teto de 65535 bindings). CSV: UTF-8 BOM, ';', fputcsv com $escape='' e apóstrofo antes de célula que começa com = + - @ (nomes de categoria/forma de pagamento são livres). Lotes de NFC-e: teto 25, eager load de currentFiscalDocument, catch Throwable por venda, set_time_limit; números "não usados" com envio ambíguo (FailedToSend sem provider_id) vêm sinalizados: podem ter sido autorizados.

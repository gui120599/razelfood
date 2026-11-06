---
paths:
  - 'app/{Actions/Fiscal/**,Actions/Sales/CancelSaleWithFiscalDocument.php,Jobs/CompleteSaleCancellationJob.php,Console/Commands/SyncFiscalDocumentsCommand.php}'
---

# Sales Commands

## Ciclo de vida fiscal: status aplicado sob lock da linha; cancelamento combinado só desfaz pendente em recusa definitiva
ApplyFiscalProviderStatus relê o documento com lockForUpdate e decide pela matriz ALLOWED sobre o estado RELIDO (webhook, polling e fiscal:sync-status competem); Cancelled é final; CancelRequested só sai por Cancelled/CancelFailed, ou vira Issued depois de 30 min sem confirmação (solta o pendente). CancelFiscalDocument só grava CancelRequested se a linha ainda é Issued (o webhook pode ter gravado Cancelled durante o DELETE). Venda combinada: pendente (sales.pending_cancellation_*) é gravado ANTES do DELETE e não é sobrescrito; FiscalDocumentException::requestMayHaveBeenSent distingue recusa definitiva (4xx: desfaz o pendente) de falha ambígua (timeout/5xx/lock: mantém, e a tela oferece "Desfazer cancelamento pendente"). CompleteSaleCancellationJob é único por venda, revalida, e failed() solta o pendente. Reconciliação: limite POR TENANT, ordem pela pendência mais antiga, janela pelo timestamp do estado (sent_at/cancel_requested_at), withoutOverlapping(30) (o mutex padrão de 24h trava o agendamento se o processo morrer). O plano Completo inclui todas as features: liberar nfe_emissao a expõe a esses tenants no migrate.

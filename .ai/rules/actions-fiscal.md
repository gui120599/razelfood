---
paths:
  - '{app/Actions/Sales/FinalizeSale.php,app/Actions/Fiscal/ApplyFiscalProviderStatus.php,routes/console.php}'
---

# Actions Fiscal

## Hospedagem sem worker: só a NFC-e automática usa dispatchAfterResponse
Fila `database` sem worker permanente (HostGator). Igual ao Pizzaria-App: IssueFiscalDocumentJob (FinalizeSale, tries=1) usa `dispatchAfterResponse` (roda após a resposta, passa pelo pipeline com o middleware de tenant, não grava em `jobs`); o job captura Throwable e só reporta, porque exceção relançada dentro do `terminate()` aborta os demais callbacks. CompleteSaleCancellationJob (tries/backoff, único por venda) continua `dispatch()->afterCommit()` na fila: dispatchAfterResponse roda em driver sync e perderia as retentativas. routes/console.php agenda `queue:work (consome esse job e os demais) --stop-when-empty --max-time=55` por minuto (withoutOverlapping) para os demais jobs. Em testes isso NÃO roda sozinho: use `Bus::fake()` + `Bus::assertDispatchedAfterResponse` (Queue::fake não captura) e, para o efeito real, `$this->runAfterResponseJobs()` de CreatesNfceFixtures (chama app()->terminate() e limpa os callbacks).

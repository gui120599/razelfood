---
paths:
  - 'app/{Http/Controllers/Webhooks/**,Support/Webhooks/**}'
---

# Webhooks Webhooks

## Webhook NFE.io: token -> tenant pelo registro, HMAC-SHA1 do corpo cru, payload limitado, documento só no escopo do tenant
NfeIoWebhookController: POST /webhooks/nfeio/{token} (webhook.tenant:nfeio; token em tenant_fiscal_settings.webhook_token, gerado em Dados fiscais, fora do $fillable). Segredo = nfeio_webhook_secret DO tenant; ausente/ilegível (DecryptException) = não autêntico (403, payload gravado antes). Assinatura: HMAC-SHA1 de getContent() no header X-Hub-Signature (tolera prefixo sha1= e maiúsculas), hash_equals. Payload gravado é o JSON cru (não a query), com UTF-8 inválido substituído e teto de 64KB (acima: {_truncated}); retenção 90 dias via model:prune agendado. O documento é buscado por provider_id SOB o TenantScope: nota de outro tenant nunca é achada (outcome unknown_document, fiscal_document_id nulo). Tenant sem a feature: grava, 200, feature_off. Todo resultado passa por ApplyFiscalProviderStatus; exceção no process vira outcome=error + 500. Resposta 200 para documento desconhecido é deliberada (reconciliação cobre); retry da NFE.io não está comprovado. Nunca logar assinatura, segredo ou token.

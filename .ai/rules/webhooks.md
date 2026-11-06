---
paths:
  - '{routes/webhooks.php,config/webhooks.php,app/Http/Middleware/ResolveTenantFromWebhookToken.php,app/Http/Controllers/Webhooks/**,app/Support/Webhooks/**}'
---

# Webhooks

## Webhooks externos: token opaco na URL, grava o payload antes de decidir 401/403
Rotas em `routes/webhooks.php` (carregado por `then:` em bootstrap/app.php, prefixo /webhooks, throttle:webhooks, SEM grupo `web` = sem sessão/CSRF). Cada rota usa `webhook.tenant:{provedor}`; o provedor se registra em `config/webhooks.php` com um `WebhookTenantResolver` (token -> Tenant, sempre pelo registro do token, nunca pelo payload). Token malformado/desconhecido = 404 sem gravar nada; tenant suspenso resolve, excluído não. O token é case-sensitive: confirme com `hash_equals` depois do lookup (collation _ci do MySQL ignora caixa). Controllers estendem `TenantWebhookController`: recordPayload() ANTES de isAuthentic(); segredo ausente = não autêntico (nunca "aceita sem verificar"). 'webhooks' é slug reservado.

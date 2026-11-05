---
paths:
  - '{app/Support/Fiscal/**,app/Models/TenantFiscalSettings.php}'
---

# Fiscal Models

## Fiscal: prontidão independe do tenant atual, não decifra segredo e relê o tenant do banco
`FiscalReadiness::forTenant()` e `ResolveProductFiscalData` rodam em job/comando/painel central, onde o CurrentTenant pode ser OUTRO: consultam `withoutGlobalScope(TenantScope::class)` + `where tenant_id` (SoftDeletes continua valendo) e o eager-load de `category`/`category.parent` também precisa tirar o TenantScope, senão a herança de categoria some. O `Tenant` recebido pode vir do cache `tenant:slug:*` (defasado): releia do banco (`Tenant::find`) para CNPJ/endereço. Presença de credencial = `getRawOriginal('nfeio_api_key')` — nunca `$settings->nfeio_api_key` só para checar `blank()`: o cast `encrypted` lança DecryptException se a APP_KEY mudar e derrubaria a tela onde o usuário redigitaria a chave. Resolução produto → categoria → categoria-pai (campo a campo; GTIN nunca herda). `unique(provider, nfeio_company_id)` no banco + regra no form (sem escopo de tenant). 1.2: revalidar prontidão e regime NA EMISSÃO, não confiar só em `enabled_at`.

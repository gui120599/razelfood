---
paths:
  - app/Http/Middleware/IdentifyTenant.php
---

# Middleware

## Cache de tenant por slug sobrevive a restart do Docker (CACHE_STORE=database)
IdentifyTenant guarda o tenant resolvido em Cache::remember("tenant:slug:{slug}", 10 min). Como CACHE_STORE=database (persistido na tabela `cache`, não em memória), reiniciar containers do Sail/Docker NÃO limpa essa entrada — ela só some no TTL de 10 min ou com Cache::forget()/cache:clear manual. Ao suspender/reativar um tenant manualmente para teste, o cardápio pode continuar bloqueado (503) por até 10 min mesmo com status=active no banco, porque o objeto Tenant cacheado ainda tem o status antigo. Se isso acontecer: Cache::forget("tenant:slug:{slug}") ou vendor/bin/sail artisan cache:clear.

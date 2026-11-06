---
paths:
  - 'app/Models/*.php'
  - app/Models/Tenant.php
---

# Models

## BelongsToMany usada em RelationManager precisa da relação inversa definida no model relacionado
Filament\Actions\AttachAction infere o nome da relação inversa a partir do model pai (ex.: FlashPromotion -> "flashPromotions") e chama esse método no model relacionado (Product) para excluir da lista de opções os registros já anexados (whereDoesntHave). Se o model relacionado não tiver esse método BelongsToMany de volta, abrir o select do Attach quebra com BadMethodCallException. Sempre que criar uma relação BelongsToMany usada por um RelationManager com AttachAction, definir também a relação inversa no model do outro lado (mesma tabela pivot, mesmo ->using()/->withPivot()). Achado real: App\Models\Product não tinha flashPromotions() enquanto FlashPromotion::products() já existia — corrigido adicionando o inverso.

Segunda instância confirmada (22/08/2026): `AddonsRelationManager` ($relationship='addons') exigiu `Product::addons()` E `Addon::products()` — a mesma regra vale em ambos os sentidos, mesmo quando só uma relação é usada diretamente pelo RelationManager.

## Cast `encrypted` exige coluna `text` e trata string vazia; cache de tenant guarda atributos crus
O payload do cast `encrypted` tem ~250+ caracteres mesmo para segredo curto: a coluna precisa ser `text` (migration separada da de dados). `''` quebra o cast (DecryptException em toda leitura) — o backfill converte para null. Backfill via DB::table (sem cast), idempotente, e faz `Cache::forget("tenant:slug:{slug}")` por tenant (o Tenant em cache guarda atributos crus). Depende de APP_KEY estável em produção. Ex.: `recaptcha_secret_key`. Credenciais de integração novas vão em tabela própria por tenant (fora de `tenants`).

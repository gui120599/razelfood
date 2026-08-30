---
paths:
  - app/Filament/Tenant/Resources/Categories/RelationManagers/FlavorQuantityOptionsRelationManager.php
---

# Relation Managers

## Closure não é bool: nunca use `$closure ? a : b` pra decidir estrutura de schema
Um objeto Closure em PHP é sempre truthy. `$isLast = fn (Get $get): bool => ...; ->rule($isLast ? null : $exceedsLimit)` SEMPRE avalia pro branch verdadeiro (nunca `$exceedsLimit`), porque `$isLast` (a Closure em si, não seu resultado) é o que está sendo testado — bug real encontrado e corrigido nesta implementação (a regra de validação nunca disparava).

Quando "isto é o último campo" (ou qualquer condição) só pode ser resolvida em tempo de validação/render (via `Get $get`, porque depende de outro campo do form, ex. `flavor_count`), NUNCA decida estruturalmente na montagem do schema (`->rule($cond ? a : b)`, `if ($cond) { ->algo() }`). Em vez disso, aplique o mesmo componente/regra a TODOS os campos candidatos e mova o `if` pra DENTRO da closure de validação/callback, testando `$get(...)` ali.

`FlavorQuantityOptionsRelationManager::shareFields()` usa esse padrão certo: `->rules([fn (Get $get): Closure => function (...) use ($get, $index) { if ($index >= $flavorCount - 1) { return; } ... }])` — a mesma regra vai em todo campo, e ela mesma decide se deve validar, lendo `flavor_count` ao vivo.

---
paths:
  - routes/web.php
---

# Routes

## Route::domain('{tenant}...') quebra injeção de parâmetro de URI por nome
Dentro de um grupo Route::domain('{tenant}.'.config(...)), um controller com type-hint de parâmetro de rota (ex.: show(Order $order) ou até string $order) recebe o valor do wildcard de domínio ({tenant}) em vez do parâmetro de URI, mesmo com nomes diferentes. Confirmado isolado (funciona sem domain group) vs pipeline HTTP real (quebra). Workaround: ler direto do request, nunca via injeção por nome/type-hint: $request->route('order'). Vale para qualquer rota nova com parâmetro de URI dentro desse grupo de domínio (Laravel 12.67 + PHP 8.5 neste projeto).

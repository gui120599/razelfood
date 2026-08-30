---
paths:
  - 'resources/css/filament/**/*.css'
---

# Filament

## Tokens de marca usados no painel Filament precisam estar em brand.css, não só em app.css
`resources/css/filament/brand.css` (compartilhado entre tenant/central theme.css) e `resources/css/app.css` (cardápio público/Breeze) são bundles Tailwind SEPARADOS — um token `@theme` definido só num deles (ex.: `--color-rf-danger`) não existe no outro. Se uma view dentro de `app/Filament/**`/`resources/views/filament/**` usa uma classe tipo `text-rf-danger`/`bg-rf-danger`, o token precisa estar declarado em `brand.css`, não basta estar em `app.css` — senão a classe é gerada sem cor (efetivamente invisível) e passa despercebido porque não há erro, só falha visual. Ao adicionar uma classe de cor de marca (`rf-*`) em qualquer Blade do painel, confirme que o token correspondente existe em `resources/css/filament/brand.css` antes de assumir que "já existe em algum lugar do projeto" é suficiente. Achado real: `rf-danger` foi usado em `attend-order.blade.php`/`flavor-picker-modal.blade.php` desde a criação do AttendOrder mas só existia em `app.css` — corrigido adicionando o token também em `brand.css`.

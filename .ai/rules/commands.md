---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## FilamentShield::getResources()/getPages() precisam de um painel "atual" setado
Fora de um request HTTP real (ex.: comando Artisan, tinker, job), `Filament::getCurrentPanel()` é null, e `FilamentShield::getResources()`/`getPages()` (usados pra resolver nomes de permissão dinamicamente) voltam um array vazio SEM erro nenhum — qualquer lógica que dependa deles (ex.: atribuir permissão de Resource/Page a um papel) falha silenciosamente. Sempre que um comando/Action rodar fora do ciclo de request e precisar consultar o FilamentShield, chamar `Filament::setCurrentPanel(Filament::getPanel('tenant'))` (ou o id do painel certo) antes. Achado real em `App\Actions\Tenants\SeedDefaultTenantRoles`.

---
paths:
  - scripts/promote-producao.sh
---

# Scripts

## Deploy: optimize:clear sempre, nunca route:cache
Em produção (HostGator, checkout de `producao` em ~/repositories/razelfood): rodar `php artisan optimize:clear` em TODO deploy após `git pull`, depois cachear só `config:cache`, `event:cache`, `view:cache`, `filament:optimize`. NÃO rodar `route:cache` — um route cache defasado já removeu a rota `livewire/livewire.min.js` (→ 404 → Livewire não carrega → painel do Filament fica inacessível, login "só recarrega"). Se cachear rota algum dia: sempre `route:clear && route:cache` juntos e conferir `route:list --path=livewire`.

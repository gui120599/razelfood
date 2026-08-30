#!/usr/bin/env bash
#
# Promove o código de `main` para a branch `producao` (deploy HostGator/cPanel).
#
# `producao` NÃO é um merge de `main` — é uma árvore reconstruída a cada
# promoção: mesmo código de app/config/database de `main`, mas
#   - COM  vendor/         (composer install --no-dev — o servidor não tem Composer)
#   - COM  public/build/   (assets já compilados)
#   - SEM  tests/          (não usado em runtime)
#   - SEM  node_modules/   (idem)
#   - .gitignore próprio (não ignora vendor/ nem public/build/)
# Por isso os hashes de commit de `main` e `producao` nunca batem mesmo quando
# o conteúdo de app/ é idêntico — é esperado, não é bug.
#
# Uso:
#   scripts/promote-producao.sh              # promove origin/main
#   scripts/promote-producao.sh <ref>        # promove outro ponto (tag, sha, branch)
#
# Roda a partir de qualquer checkout do repo (usa um worktree temporário, não
# mexe no seu checkout atual). Requer `composer`, `npm` e `git` no PATH.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

SOURCE_REF="${1:-origin/main}"

echo "==> Buscando refs de origin"
git fetch origin --quiet

SOURCE_SHA="$(git rev-parse --verify "${SOURCE_REF}^{commit}")"
echo "==> Código-fonte: ${SOURCE_REF} (${SOURCE_SHA:0:8})"

WT_DIR="$(mktemp -d)"
cleanup() {
  git worktree remove --force "$WT_DIR" 2>/dev/null || rm -rf "$WT_DIR"
}
trap cleanup EXIT

echo "==> Preparando worktree temporário em $WT_DIR"
git worktree add --detach "$WT_DIR" "$SOURCE_SHA" --quiet
cd "$WT_DIR"

if git show-ref --verify --quiet refs/remotes/origin/producao; then
  git checkout -B producao origin/producao --quiet
  BASE_MSG="Promove main (${SOURCE_SHA:0:8}) para produção"
else
  echo "==> Branch producao ainda não existe — criando"
  git checkout --orphan producao --quiet
  BASE_MSG="Cria a branch producao a partir de main (${SOURCE_SHA:0:8})"
fi

echo "==> Substituindo a árvore pelo conteúdo de ${SOURCE_REF}"
git rm -rqf -- . >/dev/null 2>&1 || true
git checkout "$SOURCE_SHA" -- .

echo "==> Escrevendo .gitignore de produção (versiona vendor/ e public/build/)"
cat > .gitignore <<'GITIGNORE'
*.log
.DS_Store
.env
.env.backup
.env.production
.phpactor.json
.phpunit.result.cache
/.claude/settings.local.json
/.claude/scheduled_tasks.lock
/.fleet
/.idea
/.nova
/.phpunit.cache
/.vscode
/.zed
/auth.json
/node_modules
/public/hot
/public/storage
/storage/*.key
/storage/pail
Homestead.json
Homestead.yaml
Thumbs.db
GITIGNORE
git add .gitignore

echo "==> Instalando dependências PHP sem dev (composer install --no-dev)"
rm -rf vendor
composer install --no-dev --optimize-autoloader --no-interaction

# O build precisa do vendor/ presente — os temas do Filament importam
# CSS de dentro de vendor/filament. Por isso o composer roda ANTES do npm.
echo "==> Compilando assets (npm ci + npm run build)"
npm ci --silent
npm run build

echo "==> Removendo tests/ e node_modules/ (fora do runtime)"
rm -rf tests node_modules
git rm -rqf -- tests node_modules >/dev/null 2>&1 || true

git add -A

if git diff --cached --quiet; then
  echo "==> Nada para promover — producao já reflete ${SOURCE_REF}."
  exit 0
fi

git commit --quiet -m "${BASE_MSG}

Regenerado por scripts/promote-producao.sh: vendor/ sem dev, public/build/
compilado, sem tests/ nem node_modules/, .gitignore próprio."

echo "==> Enviando para origin/producao"
git push origin producao

echo
echo "==> Promoção concluída. No servidor (~/repositories/razelfood):"
echo "    git pull origin producao"
echo "    php artisan optimize:clear          # limpa TUDO, inclusive route cache"
echo "    php artisan migrate --force"
echo "    php artisan config:cache"
echo "    php artisan event:cache"
echo "    php artisan view:cache"
echo "    php artisan filament:optimize"
echo "    php artisan queue:restart"
echo "    # NÃO rodar route:cache — já quebrou (rota do livewire.min.js sumia)."
echo "    #   se for cachear rota p/ perf, só com tudo estável e sempre junto:"
echo "    #   php artisan route:clear && php artisan route:cache && php artisan route:list --path=livewire"

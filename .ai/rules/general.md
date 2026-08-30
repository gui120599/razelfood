---
paths:
  - '**'
---

# General

## NUNCA rodar migrate:fresh/migrate --env=testing — não existe .env.testing, cai no dev
O projeto NÃO tem `.env.testing`. `.env` tem `DB_DATABASE=laravel` (banco de DEV). Rodar `sail artisan migrate:fresh --env=testing` (ou `--database=mysql --env=testing`) NÃO acerta o banco `testing` — o `--env=testing` procura `.env.testing`, não acha, cai no `.env` e roda no banco `laravel`, **apagando o dev inteiro** (aconteceu em 2026-08-27: 0 tenants/users/orders/permissions).

Forma correta de lidar com o banco `testing`:
- `sail artisan test` já migra sozinho via `RefreshDatabase` (phpunit.xml define `<env name="DB_DATABASE" value="testing"/>`, só vale sob phpunit).
- Se precisar migrar o `testing` manualmente: `sail artisan test --filter=NadaQueExista` (força o setup) ou criar um `.env.testing` com `DB_DATABASE=testing` primeiro.
- Se o `testing` parece corrompido no meio de uma run, quase sempre é colisão com outra sessão rodando `artisan test` em paralelo no mesmo banco — coordenar, não `migrate:fresh`.

Antes de QUALQUER comando destrutivo de schema (`migrate:fresh`, `migrate:rollback`, `db:wipe`, `migrate` com migration destrutiva), confirmar o banco alvo com `sail artisan tinker --execute 'echo DB::connection()->getDatabaseName();'` ou `config:show database.connections.mysql.database`.

Recuperação do dev (se acontecer de novo): `db:seed` (features+planos+super admin) → `shield:generate --panel=tenant --all` → recriar tenants via tinker replicando `CreateTenant::afterCreate` (User::create + `SeedDefaultTenantRoles` + `SeedDefaultTenantOptions` + `assignRole('Admin')` com `setPermissionsTeamId`) → catálogo mínimo. Dados montados à mão pelo usuário não voltam.

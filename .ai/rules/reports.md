---
paths:
  - 'app/Filament/Tenant/Pages/{Dashboard.php,Reports.php},app/Filament/Tenant/Widgets/Reports/**'
---

# Reports

## Dashboard de relatórios (RF-31) — segunda dashboard + widgets descobertos
O painel do tenant tem DUAS dashboards: `App\Filament\Tenant\Pages\Dashboard` (padrão, `/painel`) e `App\Filament\Tenant\Pages\Reports` (`/painel/relatorios`, RF-31). Ambas estendem `Filament\Pages\Dashboard`. Como `discoverWidgets` varre `app/Filament/Tenant/Widgets/**` recursivamente, os 8 widgets de `Widgets/Reports/*` são descobertos globalmente e apareceriam nas DUAS dashboards — por isso cada uma sobrescreve `getWidgets()` com a lista explícita dos seus widgets. Ao criar widget novo pra uma dashboard específica, adicione na lista de `getWidgets()` da página certa, senão ele vaza pra outra.

`Reports` usa `HasPageShield` COM alias de trait (mesmo padrão de Kitchen/OrderSettings, ver .ai/rules/pages.md) + gate por `FeatureKey::RELATORIOS`. A permissão Shield da página é `View:Reports`; `SeedDefaultTenantRoles` a concede a Admin (via `Permission::all()`) e Gerente. Os widgets de relatório NÃO usam trait de Shield — a permissão `View:<Widget>` gerada é inerte; o gate real é `canView()` no trait `ResolvesReportPeriod` (feature `relatorios`).

`config/filament-shield.php` agora exclui `App\Filament\Tenant\Pages\Dashboard` (a custom), não `Filament\Pages\Dashboard`.

Widgets de relatório filtram por `orders.opened_at` (não `created_at`) — momento em que o pedido foi feito, sempre preenchido por CreateOrderFromCart, consistente com OrdersTodayOverview. `App\Support\Reports\ReportPeriod::resolveRange()` é a fonte única do intervalo (usada por widgets via `$this->pageFilters` e pela exportação CSV `App\Actions\Reports\ExportOrdersCsv`). Rodar `shield:generate --panel=tenant --all` + `tenant:seed-roles <id>` pra cada tenant ao adicionar Page/Resource novo.

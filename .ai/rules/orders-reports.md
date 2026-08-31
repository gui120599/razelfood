---
paths:
  - 'resources/views/{orders/ticket.blade.php,components/reports/print-layout.blade.php,reports/*-print.blade.php}'
---

# Orders Reports

## Logo das vias imprimíveis: print_logo_path + show_logo_on_prints (tenant-only)
Colunas `tenants.print_logo_path` (imagem, disk public, dir `tenants/print`) e `tenants.show_logo_on_prints` (bool) editadas SÓ em `EstablishmentSettings` (painel tenant) — não estão no `TenantForm` central, igual às demais configs operacionais (delivery flow etc.). A logo só renderiza quando `$tenant->show_logo_on_prints && $tenant->print_logo_path`.

As 3 vias imprimíveis: `orders/ticket.blade.php` (comanda, edita direto) e os dois relatórios A4 (`reports/orders-print`, `reports/deliveries-print`) que compartilham `x-reports.print-layout` — mexer no bloco de logo lá cobre os dois. Todos os controllers passam `$tenant = CurrentTenant::get()`. URL da imagem: `Storage::disk('public')->url(...)`.

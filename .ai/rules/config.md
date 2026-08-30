---
paths:
  - config/filament-shield.php
---

# Config

## Permissões custom (manage_order_status, mark_order_delivered) precisam estar em custom_permissions
Qualquer permissão criada fora do padrão Resource/Page/Widget do Shield (ex.: `Permission::findOrCreate('manage_order_status')` em SeedDefaultTenantRoles) tem que estar listada em `custom_permissions` neste config, com `shield_resource.tabs.custom_permissions = true`.

Motivo: `EditRole`/`CreateRole` do Shield (vendor) fazem `syncPermissions()` (substitutivo, não aditivo) usando só os campos presentes no form. Se a permissão não aparece em nenhuma aba do form de edição de Role, ela nunca entra em `$data`, e ao salvar QUALQUER edição da role via UI (Filament > Roles), o Shield apaga silenciosamente essa permissão de todos os papéis que a tinham — inclusive do Admin. Foi a causa raiz de um bug real: os botões de ação de pedido (Kitchen/OrderResource, `OrderStatusActions::authorize('manage_order_status'|'mark_order_delivered')`) sumiam do Admin depois que alguém editava a role Admin em Shield > Roles, porque `custom_permissions` estava `[]` e a aba estava desabilitada.

Também: manter `permissions.format_custom_permission_keys = false` — como essas permissões são strings literais snake_case usadas diretamente em `->authorize('manage_order_status')` no código (não seguem o padrão `Entity:action` do Shield), formatá-las em pascal-case quebraria o match.

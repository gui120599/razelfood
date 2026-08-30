---
paths:
  - 'app/{Actions/Orders/AdvanceOrderStatus.php,Filament/Tenant/Support/OrderStatusActions.php,Filament/Tenant/Pages/Kitchen.php,Models/Order.php}'
---

# Tenant Pages

## Fluxo de entrega da Central é gateado por config do tenant
Dois booleans em `tenants` controlam como o pedido de ENTREGA avança na Central de Pedidos (padrão ambos `true` = comportamento clássico):
- `uses_in_transit_stage`: se `false`, pedido de entrega vai de "Pronto" direto pra "Finalizado" (pula "Em Transporte"); a coluna "Em Entrega" some do board (`Kitchen::boardColumns()`).
- `assigns_delivery_couriers`: se `false`, o despacho não exige escolher um Entregador; some o Select do `dispatch()`, a ação `reassignDelivery()`, e o filtro "Todos entregadores" (`Kitchen::showsDeliveryPersonnelFilter()`).

`Order::usesInTransitStage()` e `Order::assignsDeliveryCourier()` combinam `requiresDelivery()` + a flag do tenant (via `CurrentTenant::get()`). `AdvanceOrderStatus` passa `usesInTransitStage()` (não `requiresDelivery()`) para `OrderStatus::next()`. Em `OrderStatusActions`: `dispatch`/`advance` decidem visibilidade por `assignsDeliveryCourier()`; `markDelivered` também aceita `manage_order_status` quando couriers está off (senão Atendente não fecha o pedido). Config editável em `OrderSettings` (seção "Entregas").

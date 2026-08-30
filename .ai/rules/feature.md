---
paths:
  - 'tests/Feature/**/*.php'
---

# Feature

## Tenant::create() em teste que acessa Resource/Page gateado por feature precisa de plan_id
Desde a introdução do catálogo de features/planos (RN-39 a RN-44), qualquer teste que monta via Livewire::test()/HTTP a página de um Resource ou Page gateado (CategoryResource, ProductResource, FlashPromotionResource, DeliveryOptionResource, DeliveryZoneResource, PaymentOptionResource, OrderResource, ProductionLineResource, RoleResource, e as Pages Kitchen/OrderSettings) precisa que o Tenant criado no teste tenha um `plan_id` apontando pra um Plan com a feature exigida anexada (ou um override habilitado) — senão `canAccess()` retorna false e o teste quebra (403, "Call to a member function ... on null", ou "Invalid Livewire snapshot structure" quando o mount() aborta no meio do ciclo). Use a trait `Tests\Concerns\CreatesTenantWithFeatures::planWithAllFeatures()` pra pegar um plano com todas as features "always on" atuais de uma vez, em vez de montar Feature+Plan na mão em cada teste. Ver tests/Feature/FeatureGatingTest.php pro teste da gate em si.

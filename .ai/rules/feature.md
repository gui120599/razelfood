---
paths:
  - 'tests/Feature/**/*.php'
---

# Feature

## Tenant::create() em teste que acessa Resource/Page gateado por feature precisa de plan_id
Desde a introdução do catálogo de features/planos (RN-39 a RN-44), qualquer teste que monta via Livewire::test()/HTTP a página de um Resource ou Page gateado (CategoryResource, ProductResource, FlashPromotionResource, DeliveryOptionResource, DeliveryZoneResource, PaymentOptionResource, OrderResource, ProductionLineResource, RoleResource, e as Pages Kitchen/OrderSettings) precisa que o Tenant criado no teste tenha um `plan_id` apontando pra um Plan com a feature exigida anexada (ou um override habilitado) — senão `canAccess()` retorna false e o teste quebra (403, "Call to a member function ... on null", ou "Invalid Livewire snapshot structure" quando o mount() aborta no meio do ciclo). Use a trait `Tests\Concerns\CreatesTenantWithFeatures::planWithAllFeatures()` pra pegar um plano com todas as features "always on" atuais de uma vez, em vez de montar Feature+Plan na mão em cada teste. Ver tests/Feature/FeatureGatingTest.php pro teste da gate em si.

## Testes de job/HTTP: Queue::fake bloqueia dispatchSync; Http::fake repetido mantém o 1º stub; intermitência de data de corte do PDV
(1) Queue::fake() troca a fila inteira: IssueFiscalDocumentJob::dispatchSync deixa de rodar; para executar o job use a fila real (QUEUE_CONNECTION=sync) e feche a venda com forma sem emissão automática. (2) Http::fake chamado de novo NÃO substitui o stub anterior de mesmo padrão: use Http::sequence() desde o início. (3) Http::preventStrayRequests + Http::fake com closure serve para afirmar DB::transactionLevel() durante o POST. (4) Observada (não comprovada) intermitência ~1 em 4 rodadas longas: "pedido anterior à ativação do PDV" em testes antigos de Sales também (sonda de 400 iterações não reproduz; suspeita de salto de relógio no WSL2). Antes de caçar bug no código, rode de novo. (5) Pint: use SEMPRE --dirty; sem a flag reformata arquivos alheios.

---
paths:
  - app/Filament/Tenant/Resources/DeliveryZones/RelationManagers/NeighborhoodsRelationManager.php
---

# Delivery Zones Relation Managers

## Cadastro de bairros no setor de entrega: múltiplo + última cidade
O "Adicionar bairros" do NeighborhoodsRelationManager (DeliveryZoneResource) cadastra VÁRIOS bairros de uma vez e lembra a última cidade.

- Dois schemas: `form()` (EditAction, edita 1 bairro — campo `neighborhood` single) e `createFormSchema()` (CreateAction — campo `neighborhoods` com `->multiple()`).
- `CreateAction->using()` faz o loop: 1 registro por bairro via `$zone->neighborhoods()->create(['city' => ..., 'neighborhood' => ...])` (o mutator de DeliveryZoneNeighborhood normaliza city/neighborhood; `tenant_id` vem do BelongsToTenant). Retorna o último Model criado.
- Última cidade: propriedade pública `$lastCity` do RM, setada no `using()`, lida pelo `->default(fn () => $this->lastCity)` do campo `city` do create. Propriedade Livewire persiste entre aberturas do modal na mesma página.
- Validação (RN-35 "1 bairro por setor" + bairro pertence à cidade): regras compartilhadas `neighborhoodBelongsToCityRule(bool $multiple)` / `neighborhoodNotTakenRule(bool $multiple)` — no modo multiple iteram o array e falham uma vez com a lista dos bairros problemáticos.

Testes: tests/Feature/NeighborhoodsRelationManagerTest.php (campo do create é `neighborhoods`, não `neighborhood`).

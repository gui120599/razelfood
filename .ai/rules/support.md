---
paths:
  - app/Actions/Orders/Support/CartStockAndPromotionLedger.php
---

# Support

## Estoque e sales_count são rateados por sabor em combos, pelo % configurado em FlavorQuantityOption; saldo de promoção não
Decisão de negócio confirmada em 21/08/2026: uma pizza meio a meio (combo) com qty=1 debita, de CADA sabor, o % configurado em `flavor_quantity_options.flavor_shares` (ex.: 3 sabores = 33%/33%/34%, soma sempre 100%) de `stock_quantity` E de `sales_count` — não 1 unidade cheia de cada, e não uma divisão igualitária calculada em código. Isso é feito só em `CartStockAndPromotionLedger::buildConsumptionMaps()`, no bloco `$stockConsumption`, lendo `$line['resolved']['flavor_shares']` (populado por `ResolvePriceForCartLine::resolveCombo()` a partir do `FlavorQuantityOption` cujo `flavor_count` bate com a quantidade de sabores do carrinho).

O saldo de promoção relâmpago (`$promoConsumption`) continua debitando a unidade CHEIA por sabor, de propósito — não foi incluído nessa decisão, é uma conta de limite/saldo, não de estoque físico.

`Product.sales_count` foi migrado de unsignedInteger para decimal(10,2) unsigned (migration change_sales_count_to_decimal_on_products_table) pra suportar essa fração — se reverter esse rateio, reverter também o tipo da coluna ou vai truncar frações pra 0.

Precisão: como os percentuais em si são validados/gerados pra somar exatamente 100% (`FlavorQuantityOption::equalShares()`, resto sempre no último sabor), não existe mais o resíduo de arredondamento que uma divisão igualitária em código causaria (1/3 × 3 ≠ 1 em decimal(10,2)) — para `quantity=1` a soma bate exata. Para `quantity>1` na mesma linha ainda pode haver um ruído de ±0,01 por conta do arredondamento do MySQL DECIMAL(10,2) ao persistir `share × quantity`, mas não é mais sistemático (não perde sempre pro mesmo lado) — não tratado, considerado aceitável.

## Formulário de FlavorQuantityOption (percentual por sabor) — ver .ai/rules/relation-managers.md
O form de `flavor_shares` em `FlavorQuantityOptionsRelationManager` usa `TextInput` fixos por posição (não Repeater) com o último sempre calculado como resto — documentado com a armadilha do `Get`/Closure em `.ai/rules/relation-managers.md`.

## Adicionais (addons) reaproveitam flavor_shares/target_share; ordem de lock cresceu pra 4 tabelas
Feature "adicionais de produto" (RN-45 a RN-49, 22/08/2026): `buildConsumptionMaps()` agora retorna uma 3-tupla `[$promoConsumption, $stockConsumption, $addonConsumption]`. O consumo de estoque/sales_count de um adicional NUNCA calcula um rateio próprio — sempre lê `$line['resolved']['addons'][*]['target_share']`, que `ResolvePriceForCartLine::resolveAddons()` já derivou de `flavor_shares` (mesmo array usado pro rateio de sabor). Fórmula: `addon_consumption += quantity_da_linha * addon.quantity * target_share`.

Ordem de lock da tabela cresceu pra `flash_promotions → flash_promotion_products → products → addons` — `addons` sempre por último (tabela nova, sem lock cruzado pré-existente com ela, seguro acrescentar no fim). `applyDecrements()`/`applyIncrements()` ganharam 2 parâmetros finais (`Collection $stockControlledAddons, array $addonConsumption`) — sempre passar os 2 juntos, na mesma posição, em CreateOrderFromCart E UpdateOrderFromCart.

Decisão de negócio (confirmada com o usuário, com exemplo numérico literal): alvo "produto todo" num combo = fração 1.0; alvo "sabor específico" = a fração daquele sabor em `flavor_shares`. Ex.: bacon R$6 numa pizza 50/50 — sabor específico = R$3, produto todo = R$6.

Ver [[user_profile]] e o arquivo `docs/requisitos-regras-negocio.md` seção 5.2 (RN-45 a RN-49) pra regra de negócio completa.

---
paths:
  - 'app/{Actions/Menu/ResolvePriceForCartLine.php,Actions/Orders/Support/CartStockAndPromotionLedger.php,Models/ProductGift.php,Filament/Tenant/Resources/Products/RelationManagers/GiftsRelationManager.php}'
---

# Products Relation Managers

## Brindes de produto (RN-53) — como funciona e armadilhas
Feature "brinde grátis" (set/2026). Espelha os adicionais. Pivot `product_gift` (ProductGift extends Pivot, BelongsToTenant) liga Product→Product: `quantity` (unid. por unid. do pai), `is_active`, `flavor_counts` JSON (quantidades de sabores em que o brinde é oferecido; vazio/null = todas). Relações `Product::gifts()` + inversa `giftedByProducts()` — GiftsRelationManager DEVE declarar `$inverseRelationship = 'giftedByProducts'` (self-join, Filament não infere).

- `ResolvePriceForCartLine::resolveGifts()` é a fronteira de segurança: itera SÓ sobre `$product->gifts()->wherePivot('is_active',true)` (nunca a seleção do cliente, nunca `ProductGift::where('gift_product_id',...)` cru). Cliente forjando gift_product_id é ignorado, não lança. Brinde SEMPRE preço 0 — não entra em unit_price/addons_total/discount, total não muda.
- Carrinho: linha ganha `gifts: [{gift_product_id, accepted}]` (sem quantity — server resolve). `order_items.gifts` JSON = `[{gift_product_id, quantity, accepted}]` (inclui recusados accepted:false p/ a cozinha ver). quantity é snapshot.
- `CartStockAndPromotionLedger::buildConsumptionMaps()` retorna 4-tupla (4º = `$giftSalesExclusion`). Brinde aceito soma em `$stockConsumption` (lock/assert/débito de stock_quantity normais) MAS em `applyDecrements/applyIncrements` o `sales_count` usa `net = stockConsumption - giftSalesExclusion` — brinde move estoque, não vira "mais vendido". `applyDecrements`/`applyIncrements` ganharam um 9º parâmetro `array $giftSalesExclusion` — passar em Create E Update.
- Combo: brinde vale em combo, gated por `flavor_counts`. Menu.php tem sub-passo `comboBuilder['step']='gifts'` ANTES de 'addons', sem gate sim/não. No painel, FlavorPickerModal roteia p/ AddonPickerModal quando há addon OU gift; AddonPickerModal cobre os dois (giftSelections/availableGifts/toggleGift).
- Teste: MySQL JSON reordena as chaves do objeto (por tamanho, depois alfabético) → `order_items.gifts` volta como [accepted, quantity, gift_product_id]. Usar `assertEquals` (não `assertSame`) ao comparar o JSON persistido.
- Testes: ProductGiftAttachTest, Menu/ResolvePriceForCartLineGiftsTest, MenuGiftTest, Orders/OrderGiftTest, BuildOrderItemLinesTest. Shield: RelationManager não gera permissão nova (usa a policy de Product).

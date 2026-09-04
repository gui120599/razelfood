---
paths:
  - 'app/{Actions/Menu/ResolvePriceForCartLine.php,Actions/Orders/Support/CartStockAndPromotionLedger.php,Models/ProductGift.php,Support/Orders/GiftLineLabel.php}'
---

# Support Orders

## Brinde: modo de concessão award_mode (per_quantity × per_order)
RN-53 (set/2026): pivot `product_gift.award_mode` (enum App\Enums\GiftAwardMode: per_quantity | per_order), default per_quantity, cast no ProductGift, no `withPivot` das DUAS relações (gifts + giftedByProducts).

- per_quantity = escala com a quantidade da linha (3 pizzas → 3 brindes). per_order = sai UMA vez no pedido inteiro, na quantidade configurada, independente da quantidade da linha E de quantos produtos do pedido dão o mesmo brinde.
- `ResolvePriceForCartLine::resolveGifts()` põe `award_mode` (string) na linha de brinde resolvida; conflito entre 2 vínculos do mesmo brinde num combo → per_quantity vence.
- `CartStockAndPromotionLedger::buildConsumptionMaps()`: brindes per_order acumulam em `$perOrderGiftUnits[$id] = max(...)` durante o loop e são somados UMA vez a `$stockConsumption`/`$giftSalesExclusion` depois do loop (antes do return). per_quantity continua `$quantity * $giftLine['quantity']` por linha.
- `order_items.gifts` JSON agora tem `award_mode` (snapshot); consumidores usam `$gift['award_mode'] ?? 'per_quantity'` p/ pedidos antigos.
- Exibição: helper `App\Support\Orders\GiftLineLabel::accepted($gift, $lineQuantity, $name)` — per_quantity mostra `quantity × lineQty` ("🎁 3x X"), per_order mostra fixo com marcador ("🎁 1x X · por pedido"). Usado em Menu/Checkout/AttendOrder cartLines, BuildOrderItemLines, Kitchen, ItemsRelationManager, BuildWhatsAppMessage, order-details-modal.blade.
- Filament: Radio `award_mode` no GiftsRelationManager (Attach+Edit) e no bulk `attachGift` do ProductsTable; `GiftAwardMode::options()` para o array. `AttachGiftToProducts` e `ReplicateProductsToCategory` propagam `award_mode`.
- Testes: OrderGiftTest (per_order não escala; 2 produtos do pedido → 1 unidade), ResolvePriceForCartLineGiftsTest (conflito → per_quantity), BuildOrderItemLinesTest (qty efetiva + marcador).

---
paths:
  - 'app/Filament/Tenant/Livewire/Orders/{AddonPickerModal,FlavorPickerModal,ProductCatalogSelector}.php'
---

# Filament Tenant Livewire Orders

## Fluxo dos modais de pedido (sabores → adicionais) e produto simples
Todo produto simples abre o AddonPickerModal (quantidade 1..99, observação, adicionais, brindes, total); `order-item-selected` só sobra como listener da AttendOrder.
Pizza de vários sabores: FlavorPickerModal → `order-addons-requested` (com note e canGoBack) → AddonPickerModal. O modal de sabores só fica OCULTO (estado preservado) e é descartado por `order-cart-line-confirmed` ou `order-addons-cancelled`; "Voltar" dispara `order-combo-resume`.
Quantidade e observação vindas do front são limitadas no servidor (buildItem); o preço/total exibidos vêm de ResolvePriceForCartLine (preço do vínculo produto-adicional, não o do adicional). confirmAddons deve ignorar chamada com modal fechado (clique duplo) e tratar ModelNotFoundException.
O desenho da pizza é public/js/app/pizza-svg.js carregado por @assets (fora do @if do modal).

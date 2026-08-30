---
paths:
  - 'app/Actions/Menu/{ResolvePriceForProduct.php,ResolvePriceForCartLine.php,ResolvedPrice.php},app/Livewire/Menu.php'
---

# Livewire

## Promoção relâmpago pode bloquear um sabor de entrar em combo
flash_promotions.allows_flavors/max_flavors já existiam no schema e no form do Filament (FlashPromotionForm), mas nunca eram lidos em lugar nenhum da lógica de carrinho/preço até esta correção — só existiam na tela do admin, sem efeito real.

Agora:
- ResolvedPrice carrega `matchedFlashPromotion` (o model, não só o id) quando o preço vem de uma promoção relâmpago vigente.
- ResolvePriceForCartLine::resolveCombo() rejeita (InvalidArgumentException) qualquer combo que inclua um sabor cuja promoção vigente tenha allows_flavors=false — esse produto só pode ser vendido como item inteiro. Se a promoção permite sabores mas tem max_flavors próprio, esse teto é o mais restritivo que vale (mín. entre categoria e promoção).
- Menu.php::attachPrice() expõe isso pro Blade via `resolved_flavor_combo_blocked` (bool) e `resolved_flavor_combo_max` (?int) anexados ao Product — usado em product-card.blade.php pra decidir "+" direto vs abrir seletor de sabores, e no picker de sabores (menu.blade.php) pra excluir da lista quando a etapa exige 2+ sabores.
- Sabor único (flavor_count=1) continua permitido mesmo bloqueado, porque vira Cart::addSimple, não combo — a restrição só vale pra 2+.

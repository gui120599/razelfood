<?php

namespace App\Support\Orders;

/**
 * Texto de exibição de um brinde ACEITO numa linha de pedido/carrinho (RN-53).
 * Mostra a quantidade EFETIVA: no modo `per_quantity` multiplica pela
 * quantidade da linha (3 pizzas → "3x"), no modo `per_order` mantém a
 * quantidade fixa e marca "· por pedido". Cada tela acrescenta suas próprias
 * decorações ("— grátis", "(brinde)" etc.) ao redor deste núcleo.
 *
 * @param  array{gift_product_id:int, quantity:int, accepted?:bool, award_mode?:string}  $gift
 */
class GiftLineLabel
{
    public static function accepted(array $gift, int $lineQuantity, string $name): string
    {
        $configured = max(1, (int) ($gift['quantity'] ?? 1));

        if (($gift['award_mode'] ?? 'per_quantity') === 'per_order') {
            return "🎁 {$configured}x {$name} · por pedido";
        }

        $effective = $configured * max(1, $lineQuantity);

        return "🎁 {$effective}x {$name}";
    }
}

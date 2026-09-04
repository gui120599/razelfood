<?php

namespace App\Enums;

/**
 * Como o brinde vinculado a um produto (RN-53) é concedido no pedido:
 * - PerQuantity: escala com a quantidade da linha do carrinho (3 pizzas = 3 brindes);
 * - PerOrder: sai uma única vez no pedido inteiro, na quantidade configurada,
 *   independente de quantas unidades do produto principal foram pedidas e de
 *   quantos produtos do pedido oferecem o mesmo brinde.
 */
enum GiftAwardMode: string
{
    case PerQuantity = 'per_quantity';
    case PerOrder = 'per_order';

    public function label(): string
    {
        return match ($this) {
            self::PerQuantity => 'Por unidade do produto principal',
            self::PerOrder => 'Uma vez por pedido',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }
}

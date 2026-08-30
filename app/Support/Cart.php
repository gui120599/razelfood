<?php

namespace App\Support;

/**
 * Carrinho em sessão — não guarda preço (RN-13: preço é sempre resolvido de
 * novo no servidor a cada leitura). Item: {type, product_id, flavor_ids, quantity, note, addons}.
 * `product_id` de um combo é o primeiro sabor escolhido (o schema de order_items
 * exige uma FK obrigatória); `flavor_ids` traz todos os sabores, incluindo esse.
 * `addons`: array de {addon_id, quantity, target} — `target` é null (produto/
 * combo inteiro) ou um dos `flavor_ids` (sabor específico), nunca validado
 * aqui (RN-13/RN-48 — validação real acontece em ResolvePriceForCartLine).
 */
class Cart
{
    private const SESSION_KEY = 'cart';

    /**
     * @return array<int, array{type: string, product_id: int, flavor_ids: array<int>, quantity: int, note: ?string, addons: array<int, array{addon_id:int, quantity:int, target:?int}>}>
     */
    public static function items(): array
    {
        return array_values(session(self::SESSION_KEY, []));
    }

    /**
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addons
     */
    public static function addSimple(int $productId, int $quantity = 1, ?string $note = null, array $addons = []): void
    {
        self::push([
            'type' => 'simple',
            'product_id' => $productId,
            'flavor_ids' => [],
            'quantity' => max(1, $quantity),
            'note' => $note,
            'addons' => $addons,
        ]);
    }

    /**
     * @param  array<int>  $flavorIds
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addons
     */
    public static function addCombo(array $flavorIds, int $quantity = 1, ?string $note = null, array $addons = []): void
    {
        $flavorIds = array_values($flavorIds);

        self::push([
            'type' => 'combo',
            'product_id' => $flavorIds[0],
            'flavor_ids' => $flavorIds,
            'quantity' => max(1, $quantity),
            'note' => $note,
            'addons' => $addons,
        ]);
    }

    public static function updateQuantity(int $index, int $quantity): void
    {
        $items = self::items();

        if (! isset($items[$index])) {
            return;
        }

        if ($quantity < 1) {
            self::remove($index);

            return;
        }

        $items[$index]['quantity'] = $quantity;
        self::store($items);
    }

    public static function updateNote(int $index, ?string $note): void
    {
        $items = self::items();

        if (! isset($items[$index])) {
            return;
        }

        $items[$index]['note'] = $note !== '' ? $note : null;
        self::store($items);
    }

    /**
     * @param  array<int, array{addon_id:int, quantity:int, target:?int}>  $addons
     */
    public static function setAddons(int $index, array $addons): void
    {
        $items = self::items();

        if (! isset($items[$index])) {
            return;
        }

        $items[$index]['addons'] = $addons;
        self::store($items);
    }

    public static function remove(int $index): void
    {
        $items = self::items();
        unset($items[$index]);
        self::store(array_values($items));
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public static function isEmpty(): bool
    {
        return self::items() === [];
    }

    public static function totalQuantity(): int
    {
        return array_sum(array_column(self::items(), 'quantity'));
    }

    private static function push(array $item): void
    {
        $items = self::items();
        $items[] = $item;
        self::store($items);
    }

    /**
     * @param  array<int, array>  $items
     */
    private static function store(array $items): void
    {
        session([self::SESSION_KEY => array_values($items)]);
    }
}

<?php

namespace App\Actions\Menu;

use App\Enums\FlashPromotionStatus;
use App\Models\FlashPromotionProduct;
use App\Models\Product;

/**
 * RN-14: precedência de preço, mais forte pra mais fraca —
 * (1) promoção relâmpago vigente com saldo, (2) preço promocional do
 * produto dentro da janela, (3) preço normal.
 */
class ResolvePriceForProduct
{
    public function __invoke(Product $product): ResolvedPrice
    {
        $pivot = $this->findActiveFlashPromotionPivot($product);

        if ($pivot !== null) {
            return new ResolvedPrice(
                finalPrice: (float) $pivot->promotional_price,
                originalPrice: (float) $product->price,
                matchedFlashPromotionId: $pivot->flash_promotion_id,
                matchedPivotId: $pivot->id,
                matchedFlashPromotion: $pivot->flashPromotion,
            );
        }

        if ($this->hasValidProductPromo($product)) {
            return new ResolvedPrice(
                finalPrice: (float) $product->promotional_price,
                originalPrice: (float) $product->price,
            );
        }

        return new ResolvedPrice(
            finalPrice: (float) $product->price,
            originalPrice: (float) $product->price,
        );
    }

    private function findActiveFlashPromotionPivot(Product $product): ?FlashPromotionProduct
    {
        return FlashPromotionProduct::query()
            ->where('product_id', $product->id)
            ->whereHas('flashPromotion', fn ($query) => $query->where('is_active', true))
            ->with('flashPromotion')
            ->get()
            ->first(function (FlashPromotionProduct $pivot) {
                if ($pivot->flashPromotion->computedStatus() !== FlashPromotionStatus::Active) {
                    return false;
                }

                return $pivot->total_quantity === null || $pivot->sold_quantity < $pivot->total_quantity;
            });
    }

    private function hasValidProductPromo(Product $product): bool
    {
        if ($product->promotional_price === null) {
            return false;
        }

        $now = now();

        if ($product->promo_starts_at && $now->lt($product->promo_starts_at)) {
            return false;
        }

        if ($product->promo_ends_at && $now->gt($product->promo_ends_at)) {
            return false;
        }

        return true;
    }
}

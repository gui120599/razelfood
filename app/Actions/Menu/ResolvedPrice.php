<?php

namespace App\Actions\Menu;

use App\Models\FlashPromotion;

/**
 * Resolvido UMA vez e reaproveitado em toda a exibição/checkout — nunca
 * recalculado duas vezes de formas diferentes (RN-13).
 */
readonly class ResolvedPrice
{
    public function __construct(
        public float $finalPrice,
        public float $originalPrice,
        public ?int $matchedFlashPromotionId = null,
        public ?int $matchedPivotId = null,
        public ?FlashPromotion $matchedFlashPromotion = null,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->finalPrice < $this->originalPrice;
    }
}

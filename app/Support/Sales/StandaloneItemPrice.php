<?php

namespace App\Support\Sales;

use App\Actions\Menu\ResolvePriceForProduct;
use App\Models\Product;

/**
 * Preço unitário de um item AVULSO da venda: o mesmo do cardápio (RN-14),
 * exceto promoção relâmpago — ela consumiria saldo de pool, então, se uma casar,
 * o avulso usa a promoção do próprio produto (se vigente) ou o preço normal. Compartilhado por FinalizeSale e pela tela de
 * fechamento, para o preview nunca divergir do valor gravado.
 */
class StandaloneItemPrice
{
    public function __construct(private readonly ResolvePriceForProduct $resolvePriceForProduct) {}

    public function __invoke(Product $product): float
    {
        $resolved = ($this->resolvePriceForProduct)($product);

        return $resolved->matchedFlashPromotionId !== null
            ? $this->resolvePriceForProduct->withoutFlashPromotion($product)->finalPrice
            : $resolved->finalPrice;
    }
}

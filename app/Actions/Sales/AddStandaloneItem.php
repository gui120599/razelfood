<?php

namespace App\Actions\Sales;

use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Exceptions\CheckoutException;
use App\Exceptions\SaleException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\CurrentTenant;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use App\Support\Sales\StandaloneItemPrice;
use Illuminate\Support\Facades\DB;

/**
 * Lança um produto avulso no rascunho (aba Produtos). Clique repetido soma na mesma linha. O
 * estoque é só CONFERIDO aqui (considera o que o rascunho já tem do produto); a baixa acontece
 * ao finalizar. Preço = o do cardápio sem consumir promoção relâmpago (StandaloneItemPrice).
 */
class AddStandaloneItem
{
    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly StandaloneItemPrice $standaloneItemPrice,
        private readonly SyncSaleTotals $syncTotals,
    ) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $productId, int $quantity = 1): SaleItem
    {
        if ($quantity < 1) {
            throw new SaleException('A quantidade de cada item avulso deve ser de pelo menos 1.');
        }

        OpenSale::pdvCutoff();

        return DB::transaction(function () use ($sale, $productId, $quantity): SaleItem {
            $locked = OpenSale::lock($sale);

            $product = Product::query()->find($productId) ?? throw new SaleException('Produto não encontrado.');
            $line = SaleItem::query()->where('sale_id', $locked->id)->where('product_id', $product->id)->first();
            $newQuantity = ($line?->quantity ?? 0) + $quantity;

            $this->assertStock($product, $newQuantity);

            if ($line === null) {
                $unitCents = Money::toCents(($this->standaloneItemPrice)($product));

                $line = SaleItem::create([
                    'tenant_id' => CurrentTenant::id(),
                    'sale_id' => $locked->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $newQuantity,
                    'unit_price' => Money::fromCents($unitCents),
                    'total' => Money::fromCents($unitCents * $newQuantity),
                ]);
            } else {
                $line->update([
                    'quantity' => $newQuantity,
                    'total' => Money::fromCents(Money::toCents($line->unit_price) * $newQuantity),
                ]);
            }

            ($this->syncTotals)($locked);

            return $line;
        }, attempts: 3);
    }

    /**
     * @throws SaleException
     */
    private function assertStock(Product $product, int $quantity): void
    {
        $consumption = [$product->id => (float) $quantity];

        try {
            $this->ledger->assertStockAvailable($this->ledger->lockStockControlledProducts($consumption), $consumption);
        } catch (CheckoutException $exception) {
            throw new SaleException($exception->getMessage(), previous: $exception);
        }
    }
}

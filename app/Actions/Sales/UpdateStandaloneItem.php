<?php

namespace App\Actions\Sales;

use App\Actions\Orders\Support\CartStockAndPromotionLedger;
use App\Exceptions\CheckoutException;
use App\Exceptions\SaleException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Muda a quantidade de um item avulso do rascunho (o preço continua o do lançamento).
 * Estoque só conferido; a baixa é ao finalizar.
 */
class UpdateStandaloneItem
{
    public function __construct(
        private readonly CartStockAndPromotionLedger $ledger,
        private readonly SyncSaleTotals $syncTotals,
    ) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $saleItemId, int $quantity): SaleItem
    {
        if ($quantity < 1) {
            throw new SaleException('A quantidade de cada item avulso deve ser de pelo menos 1.');
        }

        return DB::transaction(function () use ($sale, $saleItemId, $quantity): SaleItem {
            $locked = OpenSale::lock($sale);
            $line = SaleItem::query()->where('sale_id', $locked->id)->whereKey($saleItemId)->first()
                ?? throw new SaleException('Item não encontrado nesta venda.');

            if ($line->product_id !== null && ($product = Product::query()->find($line->product_id)) !== null) {
                $consumption = [$product->id => (float) $quantity];

                try {
                    $this->ledger->assertStockAvailable($this->ledger->lockStockControlledProducts($consumption), $consumption);
                } catch (CheckoutException $exception) {
                    throw new SaleException($exception->getMessage(), previous: $exception);
                }
            }

            $line->update([
                'quantity' => $quantity,
                'total' => Money::fromCents(Money::toCents($line->unit_price) * $quantity),
            ]);

            ($this->syncTotals)($locked);

            return $line;
        }, attempts: 3);
    }
}

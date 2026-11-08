<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Tira um item avulso do rascunho.
 */
class RemoveStandaloneItem
{
    public function __construct(private readonly SyncSaleTotals $syncTotals) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $saleItemId): void
    {
        DB::transaction(function () use ($sale, $saleItemId): void {
            $locked = OpenSale::lock($sale);

            $deleted = SaleItem::query()->where('sale_id', $locked->id)->whereKey($saleItemId)->delete();

            if ($deleted === 0) {
                throw new SaleException('Item não encontrado nesta venda.');
            }

            ($this->syncTotals)($locked);
        }, attempts: 3);
    }
}

<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Remove um pagamento do rascunho.
 */
class RemoveSalePayment
{
    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $paymentId): void
    {
        DB::transaction(function () use ($sale, $paymentId): void {
            $locked = OpenSale::lock($sale);

            if (SalePayment::query()->where('sale_id', $locked->id)->whereKey($paymentId)->delete() === 0) {
                throw new SaleException('Pagamento não encontrado nesta venda.');
            }
        }, attempts: 3);
    }
}

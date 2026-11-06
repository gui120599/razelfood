<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Aplica (ou, com null/0, desfaz) o desconto percentual da venda em rascunho. O desconto vale
 * sobre os itens (pedidos + avulsos) e acompanha mudanças nos itens (SyncSaleTotals). Na NFC-e
 * ele é rateado em centavos entre as linhas (`discountAmount`).
 */
class ApplySaleDiscountPercent
{
    public function __construct(private readonly SyncSaleTotals $syncTotals) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, float|int|string|null $percent): Sale
    {
        $percent = $percent === null || $percent === '' ? null : round((float) $percent, 2);

        if ($percent !== null && ($percent < 0 || $percent > 100)) {
            throw new SaleException('O desconto deve ficar entre 0% e 100%.');
        }

        return DB::transaction(function () use ($sale, $percent): Sale {
            $locked = OpenSale::lock($sale);

            $locked->update(['discount_percent' => $percent === null || $percent == 0.0 ? null : $percent]);

            return ($this->syncTotals)($locked);
        }, attempts: 3);
    }
}

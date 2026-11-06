<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use Illuminate\Support\Facades\DB;

/**
 * Define o frete da venda em rascunho (campo editável da tela). Pedidos lançados somam o frete
 * deles automaticamente; o operador pode corrigir o valor aqui.
 */
class SetSaleDeliveryFee
{
    private const MAX_CENTS = 9_999_999_999;

    public function __construct(private readonly SyncSaleTotals $syncTotals) {}

    /**
     * @throws SaleException
     */
    public function __invoke(Sale $sale, float|int|string|null $fee): Sale
    {
        $cents = Money::toCents($fee);

        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new SaleException('Informe um frete válido (zero ou maior).');
        }

        return DB::transaction(function () use ($sale, $cents): Sale {
            $locked = OpenSale::lock($sale);

            $locked->update(['delivery_fee' => Money::fromCents($cents)]);

            return ($this->syncTotals)($locked);
        }, attempts: 3);
    }
}

<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Sales\OpenSale;
use App\Support\Sales\PaymentLines;
use Illuminate\Support\Facades\DB;

/**
 * Edita um pagamento do rascunho (forma, valor, bandeira, autorização).
 */
class UpdateSalePayment
{
    public function __construct(private readonly PaymentLines $paymentLines) {}

    /**
     * @param  array{payment_option_id: int, amount: float|int|string, card_brand?: ?string, card_brand_id?: int|string|null, authorization_code?: ?string}  $payment
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $paymentId, array $payment): SalePayment
    {
        return DB::transaction(function () use ($sale, $paymentId, $payment): SalePayment {
            $locked = OpenSale::lock($sale);

            $row = SalePayment::query()->where('sale_id', $locked->id)->whereKey($paymentId)->first()
                ?? throw new SaleException('Pagamento não encontrado nesta venda.');

            $row->update(AddSalePayment::attributes($this->paymentLines->validate($payment), $locked));

            return $row;
        }, attempts: 3);
    }
}

<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\CurrentTenant;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use App\Support\Sales\PaymentLines;
use Illuminate\Support\Facades\DB;

/**
 * Registra um pagamento no rascunho. Guarda o valor ENTREGUE; o troco só é calculado ao
 * finalizar (no servidor, só de linha em dinheiro) e a cobertura do total também é exigida lá.
 */
class AddSalePayment
{
    public function __construct(private readonly PaymentLines $paymentLines) {}

    /**
     * @param  array{payment_option_id: int, amount: float|int|string, card_brand?: ?string, card_brand_id?: int|string|null, authorization_code?: ?string}  $payment
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, array $payment): SalePayment
    {
        return DB::transaction(function () use ($sale, $payment): SalePayment {
            $locked = OpenSale::lock($sale);

            return SalePayment::create(self::attributes($this->paymentLines->validate($payment), $locked));
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function attributes(array $line, Sale $sale): array
    {
        $line['amount'] = Money::fromCents($line['amount_cents']);
        $line['change_amount'] = Money::fromCents(0);
        unset($line['amount_cents'], $line['change_cents']);

        return $line + ['tenant_id' => CurrentTenant::id(), 'sale_id' => $sale->id];
    }
}

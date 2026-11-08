<?php

namespace App\Actions\Sales;

use App\Exceptions\SaleException;
use App\Models\PaymentOption;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Fiscal\CentAllocator;
use App\Support\Sales\Money;
use App\Support\Sales\OpenSale;
use App\Support\Sales\PaymentLines;
use Illuminate\Support\Facades\DB;

/**
 * Dividir conta: divide o que FALTA receber em N pagamentos iguais (o resto de centavos vai para
 * os primeiros) na forma escolhida. Bandeira/autorização podem ser completadas depois pelo
 * operador; a exigência vale ao finalizar.
 */
class SplitSaleBill
{
    private const MAX_PEOPLE = 50;

    public function __construct(private readonly PaymentLines $paymentLines) {}

    /**
     * @return list<SalePayment>
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, int $people, int $paymentOptionId): array
    {
        if ($people < 2 || $people > self::MAX_PEOPLE) {
            throw new SaleException('Informe de 2 a '.self::MAX_PEOPLE.' pessoas para dividir a conta.');
        }

        return DB::transaction(function () use ($sale, $people, $paymentOptionId): array {
            $locked = OpenSale::lock($sale);
            $option = PaymentOption::query()->find($paymentOptionId) ?? throw new SaleException('Forma de pagamento inválida.');

            $receivedCents = SalePayment::query()->where('sale_id', $locked->id)->get(['id', 'amount'])
                ->sum(fn (SalePayment $payment): int => Money::toCents($payment->amount));
            $remainingCents = Money::toCents($locked->total) - $receivedCents;

            if ($remainingCents < $people) {
                throw new SaleException('Não há valor suficiente a receber para dividir entre '.$people.' pessoas.');
            }

            $created = [];

            foreach (CentAllocator::split($remainingCents, $people) as $cents) {
                $line = $this->paymentLines->validate(
                    ['payment_option_id' => $option->id, 'amount' => Money::fromCents($cents)],
                    $option,
                    enforceRequirements: false,
                );

                $created[] = SalePayment::create(AddSalePayment::attributes($line, $locked));
            }

            return $created;
        }, attempts: 3);
    }
}

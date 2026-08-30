<?php

namespace App\Actions\Orders\Support;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentOption;

/**
 * Valida e grava as parcelas de pagamento de um pedido (RN-13: soma das
 * parcelas precisa bater com o total, nunca confia em valor vindo do
 * front) — compartilhado entre CreateOrderFromCart e UpdateOrderFromCart.
 */
class RecordsOrderPayments
{
    /**
     * @param  array<int, array{payment_option_id: int, amount: float, change_for: ?float}>  $payments
     */
    public function assertPaymentsCoverTotal(array $payments, float $grandTotal): void
    {
        $sum = round(array_sum(array_column($payments, 'amount')), 2);

        if (abs($sum - $grandTotal) > 0.01) {
            throw new CheckoutException('A soma das formas de pagamento (R$ '.number_format($sum, 2, ',', '.').') não bate com o total do pedido (R$ '.number_format($grandTotal, 2, ',', '.').').');
        }
    }

    /**
     * @param  array<int, array{payment_option_id: int, amount: float, change_for: ?float}>  $payments
     */
    public function createPayments(Order $order, array $payments): void
    {
        foreach ($payments as $payment) {
            $paymentOption = PaymentOption::find($payment['payment_option_id']);

            OrderPayment::create([
                'order_id' => $order->id,
                'payment_option_name' => $paymentOption?->name ?? 'Não informado',
                'is_cash' => (bool) $paymentOption?->is_cash,
                'amount' => $payment['amount'],
                'change_for' => $payment['change_for'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array{payment_option_id: int, amount: float, change_for: ?float}>  $payments
     */
    public function replacePayments(Order $order, array $payments): void
    {
        $order->payments()->delete();
        $this->createPayments($order, $payments);
    }
}

<?php

namespace App\Actions\Orders;

use App\Models\Addon;
use App\Models\Order;
use App\Models\Product;
use App\Support\Orders\GiftLineLabel;

/**
 * RN-27: mensagem estruturada com número do pedido, itens e valores,
 * desconto, taxa de entrega, total, modalidade de entrega, endereço,
 * forma de pagamento (com troco se dinheiro) e link de acompanhamento.
 * Chamada DEPOIS do commit da transação de CreateOrderFromCart — nunca
 * dentro dela (RN-26: pedido é registrado antes do WhatsApp).
 */
class BuildWhatsAppMessage
{
    public function __invoke(Order $order): string
    {
        $lines = [];
        $lines[] = "*Pedido {$order->displayNumber()}*";
        $lines[] = '';

        $addonIds = $order->items->flatMap(fn ($item) => $item->addons ?? [])->pluck('addon_id')->unique()->values();
        $addonNames = $addonIds->isEmpty()
            ? collect()
            : Addon::withTrashed()->whereIn('id', $addonIds)->pluck('name', 'id');

        $giftIds = $order->items->flatMap(fn ($item) => $item->gifts ?? [])->pluck('gift_product_id')->unique()->values();
        $giftNames = $giftIds->isEmpty()
            ? collect()
            : Product::withTrashed()->whereIn('id', $giftIds)->pluck('name', 'id');

        foreach ($order->items as $item) {
            $name = $item->flavors
                ? Product::whereIn('id', $item->flavors)->pluck('name')->implode(' / ')
                : $item->product->name;

            $lineTotal = number_format(($item->unit_price + $item->addons_total) * $item->quantity, 2, ',', '.');
            $lines[] = "{$item->quantity}x {$name} — R$ {$lineTotal}";

            if ($item->note) {
                $lines[] = "   obs: {$item->note}";
            }

            foreach ($item->addons ?? [] as $selection) {
                $addonName = $addonNames->get($selection['addon_id'], 'Adicional removido');
                $lines[] = "   + {$selection['quantity']}x {$addonName}";
            }

            foreach ($item->gifts ?? [] as $gift) {
                if (($gift['accepted'] ?? false) !== true) {
                    continue;
                }

                $giftName = $giftNames->get($gift['gift_product_id'], 'Brinde removido');
                $lines[] = '   '.GiftLineLabel::accepted($gift, $item->quantity, $giftName).' (brinde)';
            }
        }

        $lines[] = '';
        $lines[] = 'Subtotal: R$ '.number_format($order->items_total, 2, ',', '.');

        if ($order->discount_total > 0) {
            $lines[] = 'Desconto: -R$ '.number_format($order->discount_total, 2, ',', '.');
        }

        $lines[] = 'Entrega: R$ '.number_format($order->delivery_fee, 2, ',', '.');
        $lines[] = '*Total: R$ '.number_format($order->grand_total, 2, ',', '.').'*';
        $lines[] = '';

        $lines[] = 'Modalidade: '.($order->deliveryOption?->name ?? 'Retirada no local');

        if ($order->delivery_address) {
            $lines[] = "Endereço: {$order->delivery_address}";
        }

        if ($order->is_unlisted_neighborhood) {
            $lines[] = '⚠️ Bairro fora da área mapeada — confirme se a entrega é viável antes de aceitar o pedido.';
        }

        $payments = $order->payments;
        $multiplePayments = $payments->count() > 1;

        foreach ($payments as $index => $orderPayment) {
            $payment = $multiplePayments
                ? 'Pagamento '.($index + 1).": {$orderPayment->payment_option_name} — R$ ".number_format($orderPayment->amount, 2, ',', '.')
                : "Pagamento: {$orderPayment->payment_option_name}";

            if ($orderPayment->change_for) {
                $payment .= ' (troco para R$ '.number_format($orderPayment->change_for, 2, ',', '.').')';
            }

            $lines[] = $payment;
        }

        if ($order->notes) {
            $lines[] = "Observação: {$order->notes}";
        }

        $lines[] = '';
        $lines[] = 'Acompanhe seu pedido: '.route('order.tracking', ['order' => $order->public_token]);

        return implode("\n", $lines);
    }
}

<?php

namespace App\Enums;

/**
 * Situação de pagamento DERIVADA do pedido (RN-PED-03): "pago" = o pedido faz
 * parte de uma venda finalizada. Não é coluna nem altera `OrderStatus`.
 */
enum OrderPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'A receber',
            self::Paid => 'Pago',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
        };
    }
}

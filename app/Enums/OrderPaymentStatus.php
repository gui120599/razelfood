<?php

namespace App\Enums;

/**
 * Situação de pagamento DERIVADA do pedido (RN-PED-03): "pago" = todos os itens do pedido
 * estão em vendas finalizadas; "parcial" = só alguns (venda item a item). Não é coluna nem
 * altera `OrderStatus`.
 */
enum OrderPaymentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'A receber',
            self::PartiallyPaid => 'Parcial',
            self::Paid => 'Pago',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::PartiallyPaid => 'info',
            self::Paid => 'success',
        };
    }
}

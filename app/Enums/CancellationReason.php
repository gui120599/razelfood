<?php

namespace App\Enums;

enum CancellationReason: string
{
    case CustomerGaveUp = 'customer_gave_up';
    case EntryError = 'entry_error';
    case ProductUnavailable = 'product_unavailable';
    case Delay = 'delay';
    case DuplicateTest = 'duplicate_test';
    case PaymentIssue = 'payment_issue';
    case AddressOutOfArea = 'address_out_of_area';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerGaveUp => 'Cliente desistiu',
            self::EntryError => 'Erro de lançamento',
            self::ProductUnavailable => 'Produto indisponível',
            self::Delay => 'Demora',
            self::DuplicateTest => 'Duplicado/teste',
            self::PaymentIssue => 'Problema no pagamento',
            self::AddressOutOfArea => 'Endereço fora da área',
            self::Other => 'Outro',
        };
    }
}

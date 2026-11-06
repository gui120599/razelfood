<?php

namespace App\Enums;

/**
 * Bandeira de cartão aceita no bloco `card` da NFC-e. `apiValue()` é o texto
 * que a NFE.io espera em `card.flag`; as cinco bandeiras vêm do Pizzaria-App.
 */
enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case AmericanExpress = 'american_express';
    case Sorocred = 'sorocred';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::AmericanExpress => 'American Express',
            self::Sorocred => 'Sorocred',
            self::Other => 'Outra',
        };
    }

    public function apiValue(): string
    {
        return match ($this) {
            self::Visa => 'visa',
            self::Mastercard => 'mastercard',
            self::AmericanExpress => 'americanExpress',
            self::Sorocred => 'sorocred',
            self::Other => 'other',
        };
    }
}

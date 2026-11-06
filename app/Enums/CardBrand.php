<?php

namespace App\Enums;

/**
 * Bandeira de cartão aceita no bloco `card` da NFC-e. `apiValue()` é o texto
 * enviado em `card.flag`; as oito bandeiras são as do Pizzaria-App. Comprovadas em
 * produção (570 notas emitidas lá): `Visa`, `MasterCard` e `Elo`. As demais seguem a
 * mesma grafia, ainda sem prova real.
 */
enum CardBrand: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case AmericanExpress = 'american_express';
    case Sorocred = 'sorocred';
    case Elo = 'elo';
    case Alelo = 'alelo';
    case Hipercard = 'hipercard';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::AmericanExpress => 'American Express',
            self::Sorocred => 'Sorocred',
            self::Elo => 'Elo',
            self::Alelo => 'Alelo',
            self::Hipercard => 'Hipercard',
            self::Other => 'Outra',
        };
    }

    public function apiValue(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'MasterCard',
            self::AmericanExpress => 'AmericanExpress',
            self::Sorocred => 'Sorocred',
            self::Elo => 'Elo',
            self::Alelo => 'Alelo',
            self::Hipercard => 'HiperCard',
            self::Other => 'Other',
        };
    }
}

<?php

namespace App\Enums;

/**
 * Meio de pagamento declarado na NFC-e. O valor gravado no banco segue a
 * convenção do projeto (snake_case); `apiValue()` devolve o texto que a NFE.io
 * espera (`paymentDetail.method`). A lista vem do código do Pizzaria-App; a
 * relação oficial da NFE.io ainda precisa ser confirmada antes da Fase 1.2
 * (doc migracao-nfeio §11, pergunta 3). `withoutPayment` não entra: é reservado
 * ao saldo fiado, que não existe nesta versão.
 */
enum FiscalPaymentMethod: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case StoreCredit = 'store_credit';
    case FoodVoucher = 'food_voucher';
    case MealVoucher = 'meal_voucher';
    case GiftVoucher = 'gift_voucher';
    case FuelVoucher = 'fuel_voucher';
    case BankSlip = 'bank_slip';
    case InstantPayment = 'instant_payment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Cheque => 'Cheque',
            self::CreditCard => 'Cartão de crédito',
            self::DebitCard => 'Cartão de débito',
            self::StoreCredit => 'Crédito da loja',
            self::FoodVoucher => 'Vale alimentação',
            self::MealVoucher => 'Vale refeição',
            self::GiftVoucher => 'Vale presente',
            self::FuelVoucher => 'Vale combustível',
            self::BankSlip => 'Boleto bancário',
            self::InstantPayment => 'Pagamento instantâneo (Pix)',
            self::Other => 'Outros',
        };
    }

    public function apiValue(): string
    {
        return match ($this) {
            self::Cash => 'cash',
            self::Cheque => 'cheque',
            self::CreditCard => 'creditCard',
            self::DebitCard => 'debitCard',
            self::StoreCredit => 'storeCredict',
            self::FoodVoucher => 'foodVouchers',
            self::MealVoucher => 'mealVouchers',
            self::GiftVoucher => 'giftVouchers',
            self::FuelVoucher => 'fuelVouchers',
            self::BankSlip => 'bankBill',
            self::InstantPayment => 'InstantPayment',
            self::Other => 'others',
        };
    }
}

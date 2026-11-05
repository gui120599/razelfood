<?php

namespace Tests\Unit\Enums;

use App\Enums\FiscalEnvironment;
use App\Enums\FiscalPaymentMethod;
use App\Enums\PersonType;
use App\Enums\TaxRegime;
use PHPUnit\Framework\TestCase;

class FiscalEnumsTest extends TestCase
{
    public function test_only_simples_nacional_is_supported_in_v1(): void
    {
        $this->assertTrue(TaxRegime::SimplesNacional->isSupported());

        foreach ([TaxRegime::SimplesNacionalSublimite, TaxRegime::LucroPresumido, TaxRegime::LucroReal] as $regime) {
            $this->assertFalse($regime->isSupported(), $regime->value);
        }

        $this->assertStringContainsString('Simples Nacional', TaxRegime::unsupportedMessage());
    }

    public function test_every_fiscal_payment_method_has_a_label_and_a_distinct_api_value(): void
    {
        $apiValues = [];

        foreach (FiscalPaymentMethod::cases() as $method) {
            $this->assertNotSame('', $method->label());
            $apiValues[] = $method->apiValue();
        }

        $this->assertSame($apiValues, array_values(array_unique($apiValues)), 'Valores da API não podem se repetir.');
    }

    public function test_database_values_are_snake_case_while_the_api_keeps_its_own_spelling(): void
    {
        $this->assertSame('credit_card', FiscalPaymentMethod::CreditCard->value);
        $this->assertSame('creditCard', FiscalPaymentMethod::CreditCard->apiValue());
        $this->assertSame('InstantPayment', FiscalPaymentMethod::InstantPayment->apiValue());
        $this->assertSame('cash', FiscalPaymentMethod::Cash->apiValue());

        foreach (FiscalPaymentMethod::cases() as $method) {
            $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $method->value);
        }
    }

    public function test_the_reserved_without_payment_code_is_not_selectable(): void
    {
        $this->assertNull(FiscalPaymentMethod::tryFrom('without_payment'));
        $this->assertNotContains('withoutPayment', array_map(fn (FiscalPaymentMethod $m): string => $m->apiValue(), FiscalPaymentMethod::cases()));
    }

    public function test_labels_exist_for_environment_and_person_type(): void
    {
        foreach ([...FiscalEnvironment::cases(), ...PersonType::cases(), ...TaxRegime::cases()] as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}

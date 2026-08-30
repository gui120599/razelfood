<?php

namespace Tests\Unit\Support;

use App\Filament\Support\InputMasks;
use PHPUnit\Framework\TestCase;

class InputMasksTest extends TestCase
{
    public function test_converts_a_br_formatted_string_to_a_plain_decimal(): void
    {
        $this->assertSame('1234.56', InputMasks::normalizeMoneyState('1.234,56'));
        $this->assertSame('35.90', InputMasks::normalizeMoneyState('35,90'));
    }

    public function test_leaves_values_without_a_comma_untouched(): void
    {
        $this->assertSame('35.90', InputMasks::normalizeMoneyState('35.90'));
        $this->assertSame(35.9, InputMasks::normalizeMoneyState(35.9));
        $this->assertSame(15, InputMasks::normalizeMoneyState(15));
        $this->assertNull(InputMasks::normalizeMoneyState(null));
    }

    public function test_format_phone_rebuilds_the_mask_from_stored_digits(): void
    {
        $this->assertSame('(11) 3456-7890', InputMasks::formatPhone('1134567890'));
        $this->assertSame('(11) 99999-8888', InputMasks::formatPhone('11999998888'));
        $this->assertSame('+55 (11) 99999-8888', InputMasks::formatPhone('5511999998888'));
        $this->assertSame('+55 (11) 3456-7890', InputMasks::formatPhone('551134567890'));
    }

    public function test_format_phone_returns_unexpected_lengths_untouched(): void
    {
        $this->assertSame('123', InputMasks::formatPhone('123'));
        $this->assertNull(InputMasks::formatPhone(null));
        $this->assertSame('', InputMasks::formatPhone(''));
    }

    public function test_format_cep_rebuilds_the_mask(): void
    {
        $this->assertSame('01001-000', InputMasks::formatCep('01001000'));
        $this->assertSame('01001-000', InputMasks::formatCep('01001-000'));
        $this->assertSame('123', InputMasks::formatCep('123'));
        $this->assertNull(InputMasks::formatCep(null));
    }

    public function test_format_cnpj_rebuilds_the_mask(): void
    {
        $this->assertSame('11.222.333/0001-81', InputMasks::formatCnpj('11222333000181'));
        $this->assertSame('11.222.333/0001-81', InputMasks::formatCnpj('11.222.333/0001-81'));
        $this->assertSame('123', InputMasks::formatCnpj('123'));
        $this->assertNull(InputMasks::formatCnpj(null));
    }
}

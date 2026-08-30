<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidCnpj;
use PHPUnit\Framework\TestCase;

class ValidCnpjTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        $failed = false;
        (new ValidCnpj)->validate('cnpj', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_it_passes_for_empty_values(): void
    {
        $this->assertFalse($this->fails(null));
        $this->assertFalse($this->fails(''));
    }

    public function test_it_passes_for_a_valid_cnpj_with_and_without_mask(): void
    {
        $this->assertFalse($this->fails('11.222.333/0001-81'));
        $this->assertFalse($this->fails('11222333000181'));
    }

    public function test_it_fails_for_wrong_check_digits(): void
    {
        $this->assertTrue($this->fails('11.222.333/0001-99'));
    }

    public function test_it_fails_for_wrong_length_or_repeated_digits(): void
    {
        $this->assertTrue($this->fails('123'));
        $this->assertTrue($this->fails('00000000000000'));
    }
}

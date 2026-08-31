<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidCpf;
use PHPUnit\Framework\TestCase;

class ValidCpfTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        $failed = false;

        (new ValidCpf)->validate('cpf', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    public function test_accepts_a_valid_cpf_with_and_without_mask(): void
    {
        $this->assertFalse($this->fails('52998224725'));
        $this->assertFalse($this->fails('529.982.247-25'));
    }

    public function test_rejects_a_cpf_with_a_wrong_check_digit(): void
    {
        $this->assertTrue($this->fails('52998224726'));
    }

    public function test_rejects_repeated_digits(): void
    {
        $this->assertTrue($this->fails('11111111111'));
    }

    public function test_rejects_wrong_length(): void
    {
        $this->assertTrue($this->fails('5299822472'));
        $this->assertTrue($this->fails('529982247251'));
    }

    public function test_empty_value_passes(): void
    {
        $this->assertFalse($this->fails(''));
        $this->assertFalse($this->fails(null));
    }
}

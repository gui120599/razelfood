<?php

namespace Tests\Unit\Support;

use App\Support\Sales\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{float|int|string|null, int}>
     */
    public static function toCentsCases(): array
    {
        return [
            'decimal string' => ['40.00', 4000],
            'float with noise' => [0.1 + 0.2, 30],
            'repeating thirds' => [33.33, 3333],
            'integer' => [7, 700],
            'null' => [null, 0],
            'negative' => ['-5.50', -550],
        ];
    }

    #[DataProvider('toCentsCases')]
    public function test_to_cents_never_loses_a_cent(float|int|string|null $value, int $expected): void
    {
        $this->assertSame($expected, Money::toCents($value));
    }

    public function test_from_cents_gives_a_two_decimal_dot_string(): void
    {
        $this->assertSame('0.05', Money::fromCents(5));
        $this->assertSame('1234.50', Money::fromCents(123450));
        $this->assertSame('0.00', Money::fromCents(0));
    }

    public function test_format_uses_brazilian_notation(): void
    {
        $this->assertSame('R$ 1.234,56', Money::format(123456));
        $this->assertSame('R$ 0,01', Money::format(1));
    }

    public function test_round_trip_is_exact_for_every_cent_up_to_ten_reais(): void
    {
        for ($cents = 0; $cents <= 1000; $cents++) {
            $this->assertSame($cents, Money::toCents(Money::fromCents($cents)));
        }
    }
}

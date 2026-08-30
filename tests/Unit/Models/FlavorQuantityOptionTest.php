<?php

namespace Tests\Unit\Models;

use App\Models\FlavorQuantityOption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FlavorQuantityOptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function flavorCounts(): array
    {
        return [
            '1 sabor' => [1],
            '2 sabores' => [2],
            '3 sabores' => [3],
            '4 sabores' => [4],
            '7 sabores' => [7],
        ];
    }

    #[DataProvider('flavorCounts')]
    public function test_equal_shares_always_sums_to_exactly_100(int $flavorCount): void
    {
        $shares = FlavorQuantityOption::equalShares($flavorCount);

        $this->assertCount($flavorCount, $shares);
        $this->assertEqualsWithDelta(100.0, array_sum($shares), 0.0001);
    }

    public function test_equal_shares_puts_the_rounding_remainder_on_the_last_flavor(): void
    {
        // 100 / 3 = 33,333... — os dois primeiros ficam com 33,33 e o
        // terceiro absorve o resto (33,34), pra soma bater 100 exato.
        $this->assertSame([33.33, 33.33, 33.34], FlavorQuantityOption::equalShares(3));
    }

    public function test_equal_shares_for_two_flavors_is_an_even_split(): void
    {
        $this->assertSame([50.0, 50.0], FlavorQuantityOption::equalShares(2));
    }
}

<?php

namespace Tests\Unit\Support;

use App\Support\Sales\PaymentSummary;
use PHPUnit\Framework\TestCase;

class PaymentSummaryTest extends TestCase
{
    public function test_remaining_when_the_payment_is_short(): void
    {
        $summary = PaymentSummary::fromLines(4000, [['amount_cents' => 1500, 'is_cash' => true]]);

        $this->assertSame(2500, $summary->remainingCents());
        $this->assertSame(0, $summary->changeCents());
        $this->assertFalse($summary->isCovered());
    }

    public function test_change_comes_from_cash_only(): void
    {
        $withCash = PaymentSummary::fromLines(4000, [
            ['amount_cents' => 2000, 'is_cash' => false],
            ['amount_cents' => 3000, 'is_cash' => true],
        ]);

        $this->assertSame(1000, $withCash->changeCents());
        $this->assertTrue($withCash->canGiveChange());
        $this->assertTrue($withCash->isCovered());

        $cardOnly = PaymentSummary::fromLines(4000, [['amount_cents' => 5000, 'is_cash' => false]]);

        $this->assertSame(1000, $cardOnly->changeCents());
        $this->assertFalse($cardOnly->canGiveChange());
        $this->assertFalse($cardOnly->isCovered());
    }

    public function test_exact_payment_has_no_remaining_and_no_change(): void
    {
        $summary = PaymentSummary::fromLines(3333, [['amount_cents' => 3333, 'is_cash' => false]]);

        $this->assertSame([0, 0], [$summary->remainingCents(), $summary->changeCents()]);
        $this->assertTrue($summary->isCovered());
    }

    public function test_no_lines_means_everything_is_remaining(): void
    {
        $summary = PaymentSummary::fromLines(4000, []);

        $this->assertSame(4000, $summary->remainingCents());
        $this->assertFalse($summary->isCovered());
    }
}

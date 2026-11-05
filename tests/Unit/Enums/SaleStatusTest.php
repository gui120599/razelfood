<?php

namespace Tests\Unit\Enums;

use App\Enums\OrderPaymentStatus;
use App\Enums\SaleStatus;
use PHPUnit\Framework\TestCase;

class SaleStatusTest extends TestCase
{
    public function test_only_a_finalized_sale_can_be_cancelled(): void
    {
        $this->assertTrue(SaleStatus::Finalized->canBeCancelled());
        $this->assertFalse(SaleStatus::Cancelled->canBeCancelled());
    }

    public function test_labels_and_colors_are_defined_for_every_case(): void
    {
        foreach (SaleStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertNotSame('', $status->color());
        }

        foreach (OrderPaymentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertNotSame('', $status->color());
        }

        $this->assertSame('Finalizada', SaleStatus::Finalized->label());
        $this->assertSame('A receber', OrderPaymentStatus::Pending->label());
    }
}

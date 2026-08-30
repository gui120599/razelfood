<?php

namespace Tests\Unit\Support\Orders;

use App\Enums\OrderUrgencyLevel;
use App\Support\Orders\OrderUrgencyResolver;
use PHPUnit\Framework\TestCase;

class OrderUrgencyResolverTest extends TestCase
{
    private OrderUrgencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new OrderUrgencyResolver;
    }

    public function test_null_minutes_is_normal(): void
    {
        $this->assertSame(
            OrderUrgencyLevel::Normal,
            $this->resolver->resolve(null, attentionAfterMinutes: 15, lateAfterMinutes: 30),
        );
    }

    public function test_below_attention_threshold_is_normal(): void
    {
        $this->assertSame(
            OrderUrgencyLevel::Normal,
            $this->resolver->resolve(14, attentionAfterMinutes: 15, lateAfterMinutes: 30),
        );
    }

    public function test_exactly_at_attention_threshold_is_attention(): void
    {
        $this->assertSame(
            OrderUrgencyLevel::Attention,
            $this->resolver->resolve(15, attentionAfterMinutes: 15, lateAfterMinutes: 30),
        );
    }

    public function test_between_thresholds_is_attention(): void
    {
        $this->assertSame(
            OrderUrgencyLevel::Attention,
            $this->resolver->resolve(29, attentionAfterMinutes: 15, lateAfterMinutes: 30),
        );
    }

    public function test_exactly_at_late_threshold_is_late(): void
    {
        $this->assertSame(
            OrderUrgencyLevel::Late,
            $this->resolver->resolve(30, attentionAfterMinutes: 15, lateAfterMinutes: 30),
        );
    }

    public function test_above_late_threshold_is_late(): void
    {
        $this->assertSame(
            OrderUrgencyLevel::Late,
            $this->resolver->resolve(120, attentionAfterMinutes: 15, lateAfterMinutes: 30),
        );
    }
}

<?php

namespace Tests\Unit\Enums;

use App\Enums\OrderStatus;
use App\Support\BrandColors;
use Filament\Support\Colors\Color;
use PHPUnit\Framework\TestCase;

/**
 * Fixa a tabela de status -> cor de marca da seção 2.4 de
 * docs/identidade-visual-design-system.md como contrato.
 */
class OrderStatusColorTest extends TestCase
{
    public function test_started_uses_neutral_slate(): void
    {
        $this->assertSame(Color::Slate, OrderStatus::Started->color());
    }

    public function test_open_uses_brand_teal_500(): void
    {
        $this->assertSame(Color::hex(BrandColors::TEAL_500), OrderStatus::Open->color());
    }

    public function test_preparing_uses_brand_amber_300(): void
    {
        $this->assertSame(Color::hex(BrandColors::AMBER_300), OrderStatus::Preparing->color());
    }

    public function test_ready_uses_primary_brand_color(): void
    {
        $this->assertSame('primary', OrderStatus::Ready->color());
    }

    public function test_in_transit_uses_brand_teal_300(): void
    {
        $this->assertSame(Color::hex(BrandColors::TEAL_300), OrderStatus::InTransit->color());
    }

    public function test_delivered_and_finished_use_success_semantic(): void
    {
        $this->assertSame('success', OrderStatus::Delivered->color());
        $this->assertSame('success', OrderStatus::Finished->color());
    }

    public function test_cancelled_uses_danger_semantic(): void
    {
        $this->assertSame('danger', OrderStatus::Cancelled->color());
    }
}

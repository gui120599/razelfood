<?php

namespace Tests\Feature\Promotions;

use App\Enums\FlashPromotionStatus;
use App\Models\FlashPromotion;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RN-21: o status da promoção relâmpago nunca é persistido — é sempre
 * calculado por FlashPromotion::computedStatus(). Cobre cada ramo.
 */
class FlashPromotionStatusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
    }

    private function promotion(array $attributes): FlashPromotion
    {
        return FlashPromotion::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Promo',
            'is_active' => true,
            'is_recurring' => false,
        ], $attributes));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inactive_when_flag_is_off(): void
    {
        $this->assertSame(
            FlashPromotionStatus::Inactive,
            $this->promotion(['is_active' => false])->computedStatus(),
        );
    }

    public function test_sold_out_when_pool_is_exhausted(): void
    {
        $this->assertSame(
            FlashPromotionStatus::SoldOut,
            $this->promotion(['total_quantity' => 10, 'sold_quantity' => 10])->computedStatus(),
        );
    }

    public function test_scheduled_before_the_start_of_a_one_off_window(): void
    {
        Carbon::setTestNow('2026-08-27 12:00:00');

        $this->assertSame(
            FlashPromotionStatus::Scheduled,
            $this->promotion(['starts_at' => '2026-08-28 10:00:00', 'ends_at' => '2026-08-28 20:00:00'])->computedStatus(),
        );
    }

    public function test_ended_after_a_one_off_window(): void
    {
        Carbon::setTestNow('2026-08-27 12:00:00');

        $this->assertSame(
            FlashPromotionStatus::Ended,
            $this->promotion(['starts_at' => '2026-08-26 10:00:00', 'ends_at' => '2026-08-26 20:00:00'])->computedStatus(),
        );
    }

    public function test_active_inside_a_one_off_window(): void
    {
        Carbon::setTestNow('2026-08-27 12:00:00');

        $this->assertSame(
            FlashPromotionStatus::Active,
            $this->promotion(['starts_at' => '2026-08-27 10:00:00', 'ends_at' => '2026-08-27 20:00:00'])->computedStatus(),
        );
    }

    public function test_recurring_window_that_crosses_midnight_is_active_after_midnight(): void
    {
        // Terça 01:00 — dentro da janela 22h–02h que começou na segunda.
        Carbon::setTestNow(Carbon::parse('2026-08-25 01:00:00')); // 2026-08-25 é uma terça

        $promotion = $this->promotion([
            'is_recurring' => true,
            'weekdays' => [1], // segunda
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
        ]);

        $this->assertSame(FlashPromotionStatus::Active, $promotion->computedStatus());
    }

    public function test_recurring_waiting_window_outside_the_time_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 15:00:00'));

        $promotion = $this->promotion([
            'is_recurring' => true,
            'weekdays' => [2],
            'start_time' => '18:00:00',
            'end_time' => '23:00:00',
        ]);

        $this->assertSame(FlashPromotionStatus::WaitingWindow, $promotion->computedStatus());
    }

    public function test_recurring_ended_after_recurrence_end_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 20:00:00'));

        $promotion = $this->promotion([
            'is_recurring' => true,
            'weekdays' => [2],
            'start_time' => '18:00:00',
            'end_time' => '23:00:00',
            'recurrence_end_date' => '2026-08-20',
        ]);

        $this->assertSame(FlashPromotionStatus::Ended, $promotion->computedStatus());
    }
}

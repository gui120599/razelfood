<?php

namespace Tests\Feature\Promotions;

use App\Models\FlashPromotion;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-18/RN-21: promoção relâmpago recorrente com teto tem o pool diário
 * (`sold_quantity`) zerado a cada ciclo. O comando agendado faz isso mesmo
 * que ninguém tente comprar (antes só o checkout resetava).
 */
class ResetRecurringPromotionsCommandTest extends TestCase
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
            'is_recurring' => true,
            'total_quantity' => 40,
            'sold_quantity' => 40,
        ], $attributes));
    }

    public function test_it_resets_a_recurring_promotion_not_reset_today(): void
    {
        $promotion = $this->promotion(['last_reset_at' => now()->subDay()]);

        $this->artisan('promotions:reset-recurring')->assertSuccessful();

        $this->assertSame('0.00', $promotion->fresh()->sold_quantity);
        $this->assertTrue($promotion->fresh()->last_reset_at->isToday());
    }

    public function test_it_resets_a_recurring_promotion_never_reset(): void
    {
        $promotion = $this->promotion(['last_reset_at' => null]);

        $this->artisan('promotions:reset-recurring')->assertSuccessful();

        $this->assertSame('0.00', $promotion->fresh()->sold_quantity);
    }

    public function test_it_leaves_a_promotion_already_reset_today_untouched(): void
    {
        $promotion = $this->promotion(['last_reset_at' => now()->startOfDay()->addHour(), 'sold_quantity' => 12]);

        $this->artisan('promotions:reset-recurring')->assertSuccessful();

        $this->assertSame('12.00', $promotion->fresh()->sold_quantity);
    }

    public function test_it_leaves_one_off_promotions_untouched(): void
    {
        $promotion = $this->promotion(['is_recurring' => false, 'last_reset_at' => now()->subDay(), 'sold_quantity' => 30]);

        $this->artisan('promotions:reset-recurring')->assertSuccessful();

        $this->assertSame('30.00', $promotion->fresh()->sold_quantity);
    }

    public function test_it_resets_across_tenants(): void
    {
        $promotionA = $this->promotion(['last_reset_at' => now()->subDay()]);

        $other = Tenant::create(['name' => 'Outro', 'slug' => 'outro', 'whatsapp_number' => '5511888887777']);
        $promotionB = FlashPromotion::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'name' => 'Promo B',
            'is_active' => true,
            'is_recurring' => true,
            'total_quantity' => 20,
            'sold_quantity' => 20,
            'last_reset_at' => now()->subDay(),
        ]);

        $this->artisan('promotions:reset-recurring')->assertSuccessful();

        $this->assertSame('0.00', $promotionA->fresh()->sold_quantity);
        $this->assertSame('0.00', FlashPromotion::withoutGlobalScopes()->find($promotionB->id)->sold_quantity);
    }
}

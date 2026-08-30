<?php

namespace Tests\Feature\Orders;

use App\Enums\TenantStatus;
use App\Models\BusinessHour;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Orders\ResolveActiveShiftStart;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corte das colunas Finalizados/Cancelados da Central de Pedidos pelo turno
 * de funcionamento (seção 4.1.1 do plano) — reaproveita weekday+opens_at de
 * BusinessHour sem duplicar o tratamento de meia-noite de CheckBusinessHours.
 */
class ResolveActiveShiftStartTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Tenant Teste',
            'slug' => 'tenant-teste',
            'status' => TenantStatus::Active,
            'whatsapp_number' => '5511999999999',
        ]);

        CurrentTenant::set($this->tenant);
    }

    public function test_falls_back_to_start_of_day_when_no_business_hours_are_configured(): void
    {
        $now = Carbon::create(2026, 8, 20, 14, 0, 0);

        $start = app(ResolveActiveShiftStart::class)($now);

        $this->assertTrue($start->equalTo($now->copy()->startOfDay()));
    }

    public function test_resolves_start_of_a_normal_shift_already_in_progress(): void
    {
        $now = Carbon::create(2026, 8, 20, 14, 0, 0);

        BusinessHour::create([
            'weekday' => $now->dayOfWeek,
            'opens_at' => '11:00',
            'closes_at' => '15:00',
            'is_active' => true,
        ]);

        $start = app(ResolveActiveShiftStart::class)($now);

        $this->assertTrue($start->equalTo($now->copy()->setTime(11, 0)));
    }

    public function test_resolves_start_of_a_shift_that_crosses_midnight(): void
    {
        $now = Carbon::create(2026, 8, 20, 1, 0, 0);

        BusinessHour::create([
            'weekday' => $now->copy()->subDay()->dayOfWeek,
            'opens_at' => '22:00',
            'closes_at' => '02:00',
            'is_active' => true,
        ]);

        $start = app(ResolveActiveShiftStart::class)($now);

        $this->assertTrue($start->equalTo($now->copy()->subDay()->setTime(22, 0)));
    }

    public function test_picks_the_most_recently_started_shift_among_multiple_shifts_in_the_day(): void
    {
        $now = Carbon::create(2026, 8, 20, 19, 0, 0);

        BusinessHour::create([
            'weekday' => $now->dayOfWeek,
            'opens_at' => '11:00',
            'closes_at' => '14:00',
            'is_active' => true,
        ]);

        BusinessHour::create([
            'weekday' => $now->dayOfWeek,
            'opens_at' => '18:00',
            'closes_at' => '23:00',
            'is_active' => true,
        ]);

        $start = app(ResolveActiveShiftStart::class)($now);

        $this->assertTrue($start->equalTo($now->copy()->setTime(18, 0)));
    }

    public function test_ignores_inactive_shifts(): void
    {
        $now = Carbon::create(2026, 8, 20, 14, 0, 0);

        BusinessHour::create([
            'weekday' => $now->dayOfWeek,
            'opens_at' => '11:00',
            'closes_at' => '15:00',
            'is_active' => false,
        ]);

        $start = app(ResolveActiveShiftStart::class)($now);

        $this->assertTrue($start->equalTo($now->copy()->startOfDay()));
    }
}

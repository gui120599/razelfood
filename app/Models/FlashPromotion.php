<?php

namespace App\Models;

use App\Enums\FlashPromotionStatus;
use App\Models\Concerns\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlashPromotion extends TenantScopedModel
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'is_active',
        'is_recurring',
        'starts_at',
        'ends_at',
        'weekdays',
        'start_time',
        'end_time',
        'recurrence_end_date',
        'last_reset_at',
        'total_quantity',
        'sold_quantity',
        'per_order_limit',
        'show_counter',
        'scarcity_threshold',
        'allows_flavors',
        'max_flavors',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_recurring' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'weekdays' => 'array',
            'start_time' => 'datetime:H:i:s',
            'end_time' => 'datetime:H:i:s',
            'recurrence_end_date' => 'date',
            'last_reset_at' => 'datetime',
            'sold_quantity' => 'decimal:2',
            'show_counter' => 'boolean',
            'allows_flavors' => 'boolean',
            'max_flavors' => 'integer',
        ];
    }

    public function flashPromotionProducts(): HasMany
    {
        return $this->hasMany(FlashPromotionProduct::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'flash_promotion_products')
            ->using(FlashPromotionProduct::class)
            ->withPivot(['promotional_price', 'total_quantity', 'sold_quantity']);
    }

    /**
     * Uma promoção recorrente precisa ter o `sold_quantity` (o pool do dia)
     * zerado quando o último reset não foi hoje. Chamado tanto pelo checkout
     * (CartStockAndPromotionLedger, como rede de segurança) quanto pelo
     * comando agendado `promotions:reset-recurring` — sem o comando, uma
     * promoção esgotada só "voltava" quando alguém tentava comprar.
     */
    public function needsRecurringReset(): bool
    {
        return $this->is_recurring
            && ($this->last_reset_at === null || ! $this->last_reset_at->isToday());
    }

    /**
     * RN-21: status nunca é armazenado, sempre calculado na hora.
     */
    public function computedStatus(): FlashPromotionStatus
    {
        if (! $this->is_active) {
            return FlashPromotionStatus::Inactive;
        }

        if ($this->total_quantity !== null && $this->sold_quantity >= $this->total_quantity) {
            return FlashPromotionStatus::SoldOut;
        }

        if (! $this->is_recurring) {
            $now = now();

            if ($this->starts_at && $now->lt($this->starts_at)) {
                return FlashPromotionStatus::Scheduled;
            }

            if ($this->ends_at && $now->gt($this->ends_at)) {
                return FlashPromotionStatus::Ended;
            }

            return FlashPromotionStatus::Active;
        }

        if ($this->recurrence_end_date && now()->startOfDay()->gt($this->recurrence_end_date)) {
            return FlashPromotionStatus::Ended;
        }

        return $this->isWithinRecurringWindow()
            ? FlashPromotionStatus::Active
            : FlashPromotionStatus::WaitingWindow;
    }

    private function isWithinRecurringWindow(): bool
    {
        if (blank($this->start_time) || blank($this->end_time)) {
            return false;
        }

        $weekdays = $this->weekdays ?: [0, 1, 2, 3, 4, 5, 6];
        $now = now();
        $currentTime = $now->format('H:i:s');
        $start = $this->start_time->format('H:i:s');
        $end = $this->end_time->format('H:i:s');

        if ($start <= $end) {
            return in_array($now->dayOfWeek, $weekdays, true) && $currentTime >= $start && $currentTime < $end;
        }

        // Janela cruza a meia-noite (ex.: 22h-02h).
        if ($currentTime >= $start) {
            return in_array($now->dayOfWeek, $weekdays, true);
        }

        if ($currentTime < $end) {
            $previousWeekday = ($now->dayOfWeek + 6) % 7;

            return in_array($previousWeekday, $weekdays, true);
        }

        return false;
    }
}

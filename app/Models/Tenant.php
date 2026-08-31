<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'cnpj',
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'status',
        'plan_id',
        'orders_sequence',
        'whatsapp_number',
        'logo_path',
        'favicon_path',
        'print_logo_path',
        'show_logo_on_prints',
        'primary_color',
        'watermark_height',
        'recaptcha_enabled',
        'recaptcha_site_key',
        'recaptcha_secret_key',
        'serves_unlisted_neighborhoods',
        'unlisted_neighborhood_fee',
        'order_attention_after_minutes',
        'order_late_after_minutes',
        'uses_in_transit_stage',
        'assigns_delivery_couriers',
        'require_client_cpf',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'orders_sequence' => 'integer',
            'watermark_height' => 'integer',
            'show_logo_on_prints' => 'boolean',
            'recaptcha_enabled' => 'boolean',
            'serves_unlisted_neighborhoods' => 'boolean',
            'unlisted_neighborhood_fee' => 'decimal:2',
            'uses_in_transit_stage' => 'boolean',
            'assigns_delivery_couriers' => 'boolean',
            'require_client_cpf' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function flashPromotions(): HasMany
    {
        return $this->hasMany(FlashPromotion::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    public function deliveryOptions(): HasMany
    {
        return $this->hasMany(DeliveryOption::class);
    }

    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }

    public function paymentOptions(): HasMany
    {
        return $this->hasMany(PaymentOption::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function featureOverrides(): HasMany
    {
        return $this->hasMany(TenantFeatureOverride::class);
    }

    /**
     * Feature efetiva do tenant (RN-41, RN-42): override explícito vence o
     * plano; uma feature reservada (is_available=false) nunca é liberada,
     * mesmo constando no plano ou com override enabled=true.
     */
    public function hasFeature(string $key): bool
    {
        $feature = $this->plan?->features->firstWhere('key', $key)
            ?? $this->featureOverrides->pluck('feature')->firstWhere('key', $key);

        if (! $feature || ! $feature->is_available) {
            return false;
        }

        $override = $this->featureOverrides->firstWhere('feature_id', $feature->id);
        if ($override) {
            return $override->enabled;
        }

        return $this->plan?->features->contains('id', $feature->id) ?? false;
    }
}

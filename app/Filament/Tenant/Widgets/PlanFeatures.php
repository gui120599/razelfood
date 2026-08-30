<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\Feature;
use App\Support\CurrentTenant;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class PlanFeatures extends Widget
{
    protected string $view = 'filament.tenant.widgets.plan-features';

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = '3';

    public function planName(): ?string
    {
        return CurrentTenant::get()?->plan?->name;
    }

    /**
     * @return Collection<int, Feature>
     */
    public function enabledFeatures(): Collection
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            return collect();
        }

        return Feature::where('is_available', true)
            ->orderBy('display_order')
            ->get()
            ->filter(fn (Feature $feature): bool => $tenant->hasFeature($feature->key))
            ->values();
    }
}

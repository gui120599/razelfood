<?php

namespace App\Filament\Tenant\Concerns;

use App\Support\CurrentTenant;

/**
 * Aplica RN-43: duas camadas de reforço de acesso por feature — esconde do
 * menu (shouldRegisterNavigation) e bloqueia acesso direto por URL
 * (canAccess, reavaliado a cada requisição Livewire pelo Filament).
 */
trait GatedByFeature
{
    abstract public static function requiredFeature(): string;

    public static function shouldRegisterNavigation(): bool
    {
        return static::tenantHasRequiredFeature() && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return static::tenantHasRequiredFeature() && parent::canAccess();
    }

    protected static function tenantHasRequiredFeature(): bool
    {
        return CurrentTenant::get()?->hasFeature(static::requiredFeature()) ?? false;
    }
}

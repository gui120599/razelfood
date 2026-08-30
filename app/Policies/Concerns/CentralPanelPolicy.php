<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\CentralRole;
use App\Models\User;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Base das policies do painel central (RN-44). Cada policy declara se o
 * recurso é sensível a precificação (planos/catálogo de features) — nesse
 * caso só "Plataforma"; os demais (tenants, localidades) também liberam
 * "Suporte". Todas as habilidades do Filament caem no mesmo teste.
 * Ver App\Enums\CentralRole e Database\Seeders\DatabaseSeeder.
 */
trait CentralPanelPolicy
{
    /** true = recurso ligado a precificação, restrito a CentralRole::Platform. */
    abstract protected function pricingSensitive(): bool;

    protected function allowed(AuthUser $user): bool
    {
        if (! $user instanceof User || ! $user->isCentralUser() || $user->central_role === null) {
            return false;
        }

        return $this->pricingSensitive()
            ? $user->central_role === CentralRole::Platform
            : in_array($user->central_role, [CentralRole::Platform, CentralRole::Support], true);
    }

    public function viewAny(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function view(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function create(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function update(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function delete(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function deleteAny(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function restore(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function restoreAny(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function forceDelete(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function forceDeleteAny(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function reorder(AuthUser $user): bool
    {
        return $this->allowed($user);
    }

    public function replicate(AuthUser $user): bool
    {
        return $this->allowed($user);
    }
}

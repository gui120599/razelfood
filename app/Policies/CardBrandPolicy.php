<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardBrand;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CardBrandPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CardBrand');
    }

    public function view(AuthUser $authUser, CardBrand $cardBrand): bool
    {
        return $authUser->can('View:CardBrand');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CardBrand');
    }

    public function update(AuthUser $authUser, CardBrand $cardBrand): bool
    {
        return $authUser->can('Update:CardBrand');
    }

    public function delete(AuthUser $authUser, CardBrand $cardBrand): bool
    {
        return $authUser->can('Delete:CardBrand');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CardBrand');
    }

    public function restore(AuthUser $authUser, CardBrand $cardBrand): bool
    {
        return $authUser->can('Restore:CardBrand');
    }

    public function forceDelete(AuthUser $authUser, CardBrand $cardBrand): bool
    {
        return $authUser->can('ForceDelete:CardBrand');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CardBrand');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CardBrand');
    }

    public function replicate(AuthUser $authUser, CardBrand $cardBrand): bool
    {
        return $authUser->can('Replicate:CardBrand');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CardBrand');
    }
}

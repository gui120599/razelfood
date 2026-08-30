<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Addon;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AddonPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Addon');
    }

    public function view(AuthUser $authUser, Addon $addon): bool
    {
        return $authUser->can('View:Addon');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Addon');
    }

    public function update(AuthUser $authUser, Addon $addon): bool
    {
        return $authUser->can('Update:Addon');
    }

    public function delete(AuthUser $authUser, Addon $addon): bool
    {
        return $authUser->can('Delete:Addon');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Addon');
    }

    public function restore(AuthUser $authUser, Addon $addon): bool
    {
        return $authUser->can('Restore:Addon');
    }

    public function forceDelete(AuthUser $authUser, Addon $addon): bool
    {
        return $authUser->can('ForceDelete:Addon');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Addon');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Addon');
    }

    public function replicate(AuthUser $authUser, Addon $addon): bool
    {
        return $authUser->can('Replicate:Addon');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Addon');
    }
}

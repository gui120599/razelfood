<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductionLine;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductionLinePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductionLine');
    }

    public function view(AuthUser $authUser, ProductionLine $productionLine): bool
    {
        return $authUser->can('View:ProductionLine');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductionLine');
    }

    public function update(AuthUser $authUser, ProductionLine $productionLine): bool
    {
        return $authUser->can('Update:ProductionLine');
    }

    public function delete(AuthUser $authUser, ProductionLine $productionLine): bool
    {
        return $authUser->can('Delete:ProductionLine');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductionLine');
    }

    public function restore(AuthUser $authUser, ProductionLine $productionLine): bool
    {
        return $authUser->can('Restore:ProductionLine');
    }

    public function forceDelete(AuthUser $authUser, ProductionLine $productionLine): bool
    {
        return $authUser->can('ForceDelete:ProductionLine');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductionLine');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductionLine');
    }

    public function replicate(AuthUser $authUser, ProductionLine $productionLine): bool
    {
        return $authUser->can('Replicate:ProductionLine');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductionLine');
    }
}

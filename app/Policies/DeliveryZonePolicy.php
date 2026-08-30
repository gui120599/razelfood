<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryZone;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DeliveryZonePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeliveryZone');
    }

    public function view(AuthUser $authUser, DeliveryZone $deliveryZone): bool
    {
        return $authUser->can('View:DeliveryZone');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeliveryZone');
    }

    public function update(AuthUser $authUser, DeliveryZone $deliveryZone): bool
    {
        return $authUser->can('Update:DeliveryZone');
    }

    public function delete(AuthUser $authUser, DeliveryZone $deliveryZone): bool
    {
        return $authUser->can('Delete:DeliveryZone');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeliveryZone');
    }

    public function restore(AuthUser $authUser, DeliveryZone $deliveryZone): bool
    {
        return $authUser->can('Restore:DeliveryZone');
    }

    public function forceDelete(AuthUser $authUser, DeliveryZone $deliveryZone): bool
    {
        return $authUser->can('ForceDelete:DeliveryZone');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeliveryZone');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeliveryZone');
    }

    public function replicate(AuthUser $authUser, DeliveryZone $deliveryZone): bool
    {
        return $authUser->can('Replicate:DeliveryZone');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeliveryZone');
    }
}

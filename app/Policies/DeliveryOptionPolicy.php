<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryOption;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DeliveryOptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeliveryOption');
    }

    public function view(AuthUser $authUser, DeliveryOption $deliveryOption): bool
    {
        return $authUser->can('View:DeliveryOption');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeliveryOption');
    }

    public function update(AuthUser $authUser, DeliveryOption $deliveryOption): bool
    {
        return $authUser->can('Update:DeliveryOption');
    }

    public function delete(AuthUser $authUser, DeliveryOption $deliveryOption): bool
    {
        return $authUser->can('Delete:DeliveryOption');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeliveryOption');
    }

    public function restore(AuthUser $authUser, DeliveryOption $deliveryOption): bool
    {
        return $authUser->can('Restore:DeliveryOption');
    }

    public function forceDelete(AuthUser $authUser, DeliveryOption $deliveryOption): bool
    {
        return $authUser->can('ForceDelete:DeliveryOption');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeliveryOption');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeliveryOption');
    }

    public function replicate(AuthUser $authUser, DeliveryOption $deliveryOption): bool
    {
        return $authUser->can('Replicate:DeliveryOption');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeliveryOption');
    }
}

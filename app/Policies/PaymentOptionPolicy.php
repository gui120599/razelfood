<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentOption;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PaymentOptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PaymentOption');
    }

    public function view(AuthUser $authUser, PaymentOption $paymentOption): bool
    {
        return $authUser->can('View:PaymentOption');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentOption');
    }

    public function update(AuthUser $authUser, PaymentOption $paymentOption): bool
    {
        return $authUser->can('Update:PaymentOption');
    }

    public function delete(AuthUser $authUser, PaymentOption $paymentOption): bool
    {
        return $authUser->can('Delete:PaymentOption');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PaymentOption');
    }

    public function restore(AuthUser $authUser, PaymentOption $paymentOption): bool
    {
        return $authUser->can('Restore:PaymentOption');
    }

    public function forceDelete(AuthUser $authUser, PaymentOption $paymentOption): bool
    {
        return $authUser->can('ForceDelete:PaymentOption');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PaymentOption');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PaymentOption');
    }

    public function replicate(AuthUser $authUser, PaymentOption $paymentOption): bool
    {
        return $authUser->can('Replicate:PaymentOption');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PaymentOption');
    }
}

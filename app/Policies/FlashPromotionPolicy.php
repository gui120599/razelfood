<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FlashPromotion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FlashPromotionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FlashPromotion');
    }

    public function view(AuthUser $authUser, FlashPromotion $flashPromotion): bool
    {
        return $authUser->can('View:FlashPromotion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FlashPromotion');
    }

    public function update(AuthUser $authUser, FlashPromotion $flashPromotion): bool
    {
        return $authUser->can('Update:FlashPromotion');
    }

    public function delete(AuthUser $authUser, FlashPromotion $flashPromotion): bool
    {
        return $authUser->can('Delete:FlashPromotion');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FlashPromotion');
    }

    public function restore(AuthUser $authUser, FlashPromotion $flashPromotion): bool
    {
        return $authUser->can('Restore:FlashPromotion');
    }

    public function forceDelete(AuthUser $authUser, FlashPromotion $flashPromotion): bool
    {
        return $authUser->can('ForceDelete:FlashPromotion');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FlashPromotion');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FlashPromotion');
    }

    public function replicate(AuthUser $authUser, FlashPromotion $flashPromotion): bool
    {
        return $authUser->can('Replicate:FlashPromotion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FlashPromotion');
    }
}

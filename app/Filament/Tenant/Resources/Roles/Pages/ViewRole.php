<?php

namespace App\Filament\Tenant\Resources\Roles\Pages;

use App\Filament\Tenant\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ViewRole as BaseViewRole;

class ViewRole extends BaseViewRole
{
    protected static string $resource = RoleResource::class;
}

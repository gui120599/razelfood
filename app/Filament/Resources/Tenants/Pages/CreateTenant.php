<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Actions\Tenants\SeedDefaultTenantOptions;
use App\Actions\Tenants\SeedDefaultTenantRoles;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** @var array{name: string, email: string, password: string} */
    protected array $adminData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->adminData = [
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
        ];

        unset($data['admin_name'], $data['admin_email'], $data['admin_password']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $tenant = $this->record;

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => $this->adminData['name'],
            'email' => $this->adminData['email'],
            'password' => $this->adminData['password'],
        ]);

        app(SeedDefaultTenantRoles::class)($tenant);
        app(SeedDefaultTenantOptions::class)($tenant);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $admin->assignRole('Admin');
    }
}

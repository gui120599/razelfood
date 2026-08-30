<?php

namespace App\Filament\Tenant\Resources\Users\Pages;

use App\Filament\Tenant\Resources\Users\UserResource;
use App\Support\CurrentTenant;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = CurrentTenant::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncRoles(
            Role::query()->whereIn('id', $this->data['roleIds'] ?? [])->get()
        );
    }
}

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
        // Filtra por tenant além do whereIn: o Select do form só oferece
        // papéis do tenant atual, mas o payload de `roleIds` é do cliente —
        // sem este where um Admin poderia forjar o id de um papel de outro
        // tenant. syncRoles() grava a pivot no team certo (setado por
        // ApplyTenantScopes), mas não revalida o id.
        $this->record->syncRoles(
            Role::query()
                ->where(config('permission.column_names.team_foreign_key'), CurrentTenant::id())
                ->whereIn('id', $this->data['roleIds'] ?? [])
                ->get()
        );
    }
}

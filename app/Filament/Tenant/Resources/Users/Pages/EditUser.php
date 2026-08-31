<?php

namespace App\Filament\Tenant\Resources\Users\Pages;

use App\Filament\Tenant\Resources\Users\UserResource;
use App\Support\CurrentTenant;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => $this->record->id !== auth()->id()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roleIds'] = $this->record->roles->pluck('id')->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        // Filtra por tenant além do whereIn — ver comentário em CreateUser.
        $this->record->syncRoles(
            Role::query()
                ->where(config('permission.column_names.team_foreign_key'), CurrentTenant::id())
                ->whereIn('id', $this->data['roleIds'] ?? [])
                ->get()
        );
    }
}

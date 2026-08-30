<?php

namespace App\Filament\Tenant\Resources\Addons\Pages;

use App\Filament\Tenant\Resources\Addons\AddonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAddon extends EditRecord
{
    protected static string $resource = AddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

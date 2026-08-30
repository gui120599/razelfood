<?php

namespace App\Filament\Tenant\Resources\Addons\Pages;

use App\Filament\Tenant\Resources\Addons\AddonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAddons extends ListRecords
{
    protected static string $resource = AddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

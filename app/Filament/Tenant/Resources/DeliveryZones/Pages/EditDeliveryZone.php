<?php

namespace App\Filament\Tenant\Resources\DeliveryZones\Pages;

use App\Filament\Tenant\Resources\DeliveryZones\DeliveryZoneResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeliveryZone extends EditRecord
{
    protected static string $resource = DeliveryZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

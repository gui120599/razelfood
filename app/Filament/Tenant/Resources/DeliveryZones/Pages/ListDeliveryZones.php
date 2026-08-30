<?php

namespace App\Filament\Tenant\Resources\DeliveryZones\Pages;

use App\Filament\Tenant\Resources\DeliveryZones\DeliveryZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryZones extends ListRecords
{
    protected static string $resource = DeliveryZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

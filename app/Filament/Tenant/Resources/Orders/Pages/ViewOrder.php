<?php

namespace App\Filament\Tenant\Resources\Orders\Pages;

use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Support\OrderStatusActions;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $arguments = ['order' => $this->record->id];

        return [
            OrderStatusActions::deliveryLink()->arguments($arguments),
            OrderStatusActions::reassignDelivery()->arguments($arguments),
            OrderStatusActions::markDelivered()->arguments($arguments),
            OrderStatusActions::dispatch()->arguments($arguments),
            OrderStatusActions::advance()->arguments($arguments),
            OrderStatusActions::printTicket()->arguments($arguments),
            OrderStatusActions::cancel()->arguments($arguments),
        ];
    }
}

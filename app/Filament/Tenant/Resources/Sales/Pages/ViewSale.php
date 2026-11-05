<?php

namespace App\Filament\Tenant\Resources\Sales\Pages;

use App\Filament\Tenant\Resources\Sales\SaleResource;
use App\Filament\Tenant\Support\SaleActions;
use Filament\Resources\Pages\ViewRecord;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SaleActions::cancel(),
        ];
    }
}

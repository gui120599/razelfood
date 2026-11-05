<?php

namespace App\Filament\Tenant\Resources\Sales\Pages;

use App\Filament\Tenant\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;
}

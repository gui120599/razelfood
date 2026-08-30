<?php

namespace App\Filament\Tenant\Resources\ProductionLines\Pages;

use App\Filament\Tenant\Resources\ProductionLines\ProductionLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionLine extends CreateRecord
{
    protected static string $resource = ProductionLineResource::class;
}

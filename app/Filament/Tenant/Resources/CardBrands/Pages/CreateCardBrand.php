<?php

namespace App\Filament\Tenant\Resources\CardBrands\Pages;

use App\Filament\Tenant\Resources\CardBrands\CardBrandResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCardBrand extends CreateRecord
{
    protected static string $resource = CardBrandResource::class;
}

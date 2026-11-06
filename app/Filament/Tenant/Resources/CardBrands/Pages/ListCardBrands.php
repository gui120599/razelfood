<?php

namespace App\Filament\Tenant\Resources\CardBrands\Pages;

use App\Filament\Tenant\Resources\CardBrands\CardBrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCardBrands extends ListRecords
{
    protected static string $resource = CardBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

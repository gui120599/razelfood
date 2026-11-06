<?php

namespace App\Filament\Tenant\Resources\CardBrands\Pages;

use App\Filament\Tenant\Resources\CardBrands\CardBrandResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCardBrand extends EditRecord
{
    protected static string $resource = CardBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

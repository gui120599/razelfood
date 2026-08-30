<?php

namespace App\Filament\Tenant\Resources\FlashPromotions\Pages;

use App\Filament\Tenant\Resources\FlashPromotions\FlashPromotionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlashPromotions extends ListRecords
{
    protected static string $resource = FlashPromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

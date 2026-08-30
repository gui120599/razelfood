<?php

namespace App\Filament\Tenant\Resources\FlashPromotions\Pages;

use App\Filament\Tenant\Resources\FlashPromotions\FlashPromotionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFlashPromotion extends EditRecord
{
    protected static string $resource = FlashPromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Tenant\Resources\PaymentOptions\Pages;

use App\Filament\Tenant\Resources\PaymentOptions\PaymentOptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentOption extends EditRecord
{
    protected static string $resource = PaymentOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

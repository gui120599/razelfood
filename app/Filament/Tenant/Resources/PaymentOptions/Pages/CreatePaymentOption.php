<?php

namespace App\Filament\Tenant\Resources\PaymentOptions\Pages;

use App\Filament\Tenant\Resources\PaymentOptions\PaymentOptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentOption extends CreateRecord
{
    protected static string $resource = PaymentOptionResource::class;
}

<?php

namespace App\Filament\Tenant\Resources\FiscalDocuments\Pages;

use App\Filament\Tenant\Resources\FiscalDocuments\FiscalDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;
}

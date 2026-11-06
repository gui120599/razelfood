<?php

namespace App\Filament\Tenant\Resources\FiscalDocuments\Pages;

use App\Filament\Tenant\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Tenant\Support\FiscalDocumentActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewFiscalDocument extends ViewRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FiscalDocumentActions::issue(),
            FiscalDocumentActions::cancel(),
            FiscalDocumentActions::refresh(),
            FiscalDocumentActions::danfe(),
        ];
    }

    /**
     * Depois de emitir/atualizar/cancelar, recarrega o documento e a venda (o `documentOf` das
     * ações lê a relação já carregada, que ficaria com o estado antigo).
     */
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        $this->record->unsetRelation('sale');
        $this->record->refresh();
    }
}

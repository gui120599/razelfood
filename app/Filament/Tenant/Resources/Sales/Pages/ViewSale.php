<?php

namespace App\Filament\Tenant\Resources\Sales\Pages;

use App\Actions\Fiscal\SyncFiscalDocumentStatus;
use App\Exceptions\FiscalDocumentException;
use App\Filament\Tenant\Resources\Sales\SaleResource;
use App\Filament\Tenant\Support\FiscalDocumentActions;
use App\Filament\Tenant\Support\SaleActions;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FiscalDocumentActions::issue(),
            FiscalDocumentActions::refresh(),
            FiscalDocumentActions::danfe(),
            SaleActions::cancel(),
        ];
    }

    /**
     * Chamado pelo `wire:poll` da seção "Documento fiscal" enquanto a nota está em
     * processamento (e só dentro da janela de polling): consulta a NFE.io e atualiza a
     * tela. Exige a feature e `emit_fiscal_document` — é um método público do Livewire e
     * usa as credenciais do estabelecimento. Qualquer falha é registrada e não interrompe a tela;
     * o botão "Atualizar status" mostra o motivo.
     */
    public function pollFiscalDocument(): void
    {
        if (! FiscalDocumentActions::tenantUsesFiscal() || ! (auth()->user()?->can('emit_fiscal_document') ?? false)) {
            return;
        }

        $this->record->unsetRelation('currentFiscalDocument');
        $document = $this->record->currentFiscalDocument;

        if ($document?->shouldPoll()) {
            try {
                app(SyncFiscalDocumentStatus::class)($document);
            } catch (FiscalDocumentException) {
                // Mensagem segura já tratada pelo botão manual.
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->record->unsetRelation('currentFiscalDocument');
        $this->record->load('currentFiscalDocument');
    }
}

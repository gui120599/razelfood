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
            FiscalDocumentActions::cancel(),
            FiscalDocumentActions::refresh(),
            FiscalDocumentActions::danfe(),
            SaleActions::resume(),
            SaleActions::discardPendingCancellation(),
            SaleActions::cancel(),
        ];
    }

    /**
     * Chamado pelo `wire:poll` da seção "Documento fiscal" enquanto a nota está em
     * processamento ou o cancelamento da venda está pendente (dentro da janela de polling):
     * recarrega a venda e, SÓ com a feature e `emit_fiscal_document` (é um método público do
     * Livewire e usa as credenciais do estabelecimento), consulta a NFE.io. Qualquer falha é registrada e não interrompe a tela;
     * o botão "Atualizar status" mostra o motivo.
     */
    public function pollFiscalDocument(): void
    {
        $this->record->refresh();

        $canSync = FiscalDocumentActions::tenantUsesFiscal() && (auth()->user()?->can('emit_fiscal_document') ?? false);

        if ($canSync) {
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
        }

        $this->record->refresh();
        $this->record->unsetRelation('currentFiscalDocument');
        $this->record->load('currentFiscalDocument');
    }
}

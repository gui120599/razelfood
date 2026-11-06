<?php

namespace App\Filament\Tenant\Support;

use App\Actions\Fiscal\CancelFiscalDocument;
use App\Actions\Fiscal\IssueFiscalDocument;
use App\Actions\Fiscal\SyncFiscalDocumentStatus;
use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\SaleStatus;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\NfeIoException;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use App\Models\TenantFiscalSettings;
use App\Services\Fiscal\NfeIoClient;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Ações de NFC-e na tela da venda: emitir/reenviar, atualizar status e imprimir o DANFE.
 * Só existem com a feature `nfe_emissao`; emitir exige `emit_fiscal_document`. Toda regra
 * (revalidação, lock, numeração) vive em IssueFiscalDocument — aqui só se traduz o resultado.
 */
class FiscalDocumentActions
{
    public static function tenantUsesFiscal(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false;
    }

    public static function issue(): Action
    {
        return Action::make('issueFiscalDocument')
            ->label(fn (Sale $record): string => in_array($record->currentFiscalDocument?->status, [FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Failed, FiscalDocumentStatus::Reserved], true)
                ? 'Reenviar NFC-e'
                : 'Emitir NFC-e')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->authorize('emit_fiscal_document')
            ->visible(fn (Sale $record): bool => self::tenantUsesFiscal()
                && $record->status === SaleStatus::Finalized
                && ! $record->hasLiveFiscalDocument())
            ->requiresConfirmation()
            ->modalHeading('Emitir NFC-e')
            ->modalDescription(fn (Sale $record): string => $record->currentFiscalDocument?->isAmbiguousSend()
                ? 'ATENÇÃO: a tentativa anterior pode ter chegado à NFE.io (houve falha de conexão ou erro do servidor). Confira no painel da NFE.io se a nota já existe antes de reenviar — reenviar pode duplicar a NFC-e.'
                : 'A nota fiscal será enviada à SEFAZ pela NFE.io. Confira os itens e as formas de pagamento da venda antes de continuar.')
            ->action(function (Sale $record): void {
                try {
                    $document = app(IssueFiscalDocument::class)($record, FiscalDocumentDecision::Manual, auth()->user());
                } catch (FiscalDocumentException $exception) {
                    Notification::make()->title('Não foi possível emitir a NFC-e')->body($exception->getMessage())->danger()->send();

                    return;
                }

                match ($document->status) {
                    FiscalDocumentStatus::Issued => Notification::make()->title('NFC-e emitida')->success()->send(),
                    FiscalDocumentStatus::Processing => Notification::make()->title('NFC-e enviada: aguardando a autorização')->success()->send(),
                    default => Notification::make()->title('A NFC-e não foi enviada')->body((string) $document->error_message)->danger()->send(),
                };
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancelFiscalDocument')
            ->label('Cancelar NFC-e')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('cancel_fiscal_document')
            ->visible(fn (Sale $record): bool => self::tenantUsesFiscal()
                && $record->currentFiscalDocument?->status === FiscalDocumentStatus::Issued
                && ! $record->hasPendingCancellation())
            ->requiresConfirmation()
            ->modalHeading('Cancelar NFC-e')
            ->modalDescription('O cancelamento é pedido à NFE.io e confirmado pela SEFAZ; pode ser recusado se estiver fora do prazo. A venda continua como está. Para cancelar a venda junto, use "Cancelar venda".')
            ->action(function (Sale $record): void {
                try {
                    app(CancelFiscalDocument::class)($record->currentFiscalDocument, auth()->user());
                } catch (FiscalDocumentException $exception) {
                    Notification::make()->title('Não foi possível cancelar a NFC-e')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Cancelamento da NFC-e solicitado: aguardando a confirmação da NFE.io')->success()->send();
            });
    }

    public static function refresh(): Action
    {
        return Action::make('refreshFiscalDocument')
            ->label('Atualizar status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize('emit_fiscal_document')
            ->visible(fn (Sale $record): bool => self::tenantUsesFiscal()
                && filled($record->currentFiscalDocument?->provider_id)
                && $record->currentFiscalDocument->status !== FiscalDocumentStatus::Cancelled)
            ->action(function (Sale $record): void {
                try {
                    $document = app(SyncFiscalDocumentStatus::class)($record->currentFiscalDocument);
                } catch (FiscalDocumentException $exception) {
                    Notification::make()->title('Não foi possível consultar a NFC-e')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Status: '.$document->status->label())->success()->send();
            });
    }

    public static function danfe(): Action
    {
        return Action::make('printDanfe')
            ->label('Imprimir DANFE')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (Sale $record): bool => self::tenantUsesFiscal()
                && $record->currentFiscalDocument?->status === FiscalDocumentStatus::Issued
                && filled($record->currentFiscalDocument->provider_id))
            ->action(function (Sale $record, Component $livewire): void {
                $document = $record->currentFiscalDocument;
                $settings = TenantFiscalSettings::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $document->tenant_id)->first();

                if ($settings === null) {
                    Notification::make()->title('Os dados fiscais do estabelecimento não foram encontrados')->danger()->send();

                    return;
                }

                try {
                    $url = app(NfeIoClient::class)->pdfUrl($settings, $document->provider_id);
                } catch (NfeIoException $exception) {
                    Notification::make()->title('Não foi possível obter o DANFE')->body($exception->getMessage())->danger()->send();

                    return;
                }

                if (! str_starts_with(strtolower($url), 'https://')) {
                    Notification::make()->title('A NFE.io devolveu um endereço de DANFE inválido')->danger()->send();

                    return;
                }

                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }
}

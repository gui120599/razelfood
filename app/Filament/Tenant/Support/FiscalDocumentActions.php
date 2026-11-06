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
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use App\Models\TenantFiscalSettings;
use App\Services\Fiscal\NfeIoClient;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Throwable;

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

    /**
     * A venda do registro: a própria venda (tela/tabela de Vendas) ou a do documento (lista de documentos).
     */
    private static function saleOf(Model $record): ?Sale
    {
        return $record instanceof FiscalDocument ? $record->sale : $record;
    }

    /**
     * O documento "atual" da venda sobre o qual as ações operam. Numa linha da lista de documentos,
     * só a nota mais recente da venda aceita ações (as antigas, canceladas, são histórico).
     */
    private static function documentOf(Model $record): ?FiscalDocument
    {
        $current = self::saleOf($record)?->currentFiscalDocument;

        if ($record instanceof FiscalDocument) {
            return $current?->is($record) ? $record : null;
        }

        return $current;
    }

    private static function isCurrent(Model $record): bool
    {
        return ! ($record instanceof FiscalDocument) || self::documentOf($record) !== null;
    }

    public static function issue(): Action
    {
        return Action::make('issueFiscalDocument')
            ->label(fn (Model $record): string => in_array(self::documentOf($record)?->status, [FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Failed, FiscalDocumentStatus::Reserved], true)
                ? 'Reenviar NFC-e'
                : 'Emitir NFC-e')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->authorize('emit_fiscal_document')
            ->visible(fn (Model $record): bool => self::tenantUsesFiscal()
                && self::saleOf($record) !== null
                && self::isCurrent($record)
                && self::saleOf($record)->status === SaleStatus::Finalized
                && ! self::saleOf($record)->hasLiveFiscalDocument())
            ->requiresConfirmation()
            ->modalHeading('Emitir NFC-e')
            ->modalDescription(fn (Model $record): string => self::documentOf($record)?->isAmbiguousSend()
                ? 'ATENÇÃO: a tentativa anterior pode ter chegado à NFE.io (houve falha de conexão ou erro do servidor). Confira no painel da NFE.io se a nota já existe antes de reenviar — reenviar pode duplicar a NFC-e.'
                : 'A nota fiscal será enviada à SEFAZ pela NFE.io. Confira os itens e as formas de pagamento da venda antes de continuar.')
            ->action(function (Model $record): void {
                try {
                    $document = app(IssueFiscalDocument::class)(self::saleOf($record), FiscalDocumentDecision::Manual, auth()->user());
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
            ->visible(fn (Model $record): bool => self::tenantUsesFiscal()
                && self::documentOf($record)?->status === FiscalDocumentStatus::Issued
                && ! self::saleOf($record)?->hasPendingCancellation())
            ->requiresConfirmation()
            ->modalHeading('Cancelar NFC-e')
            ->modalDescription('O cancelamento é pedido à NFE.io e confirmado pela SEFAZ; pode ser recusado se estiver fora do prazo. A venda continua como está. Para cancelar a venda junto, use "Cancelar venda".')
            ->action(function (Model $record): void {
                try {
                    app(CancelFiscalDocument::class)(self::documentOf($record), auth()->user());
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
            ->visible(fn (Model $record): bool => self::tenantUsesFiscal()
                && filled(self::documentOf($record)?->provider_id)
                && self::documentOf($record)->status !== FiscalDocumentStatus::Cancelled)
            ->action(function (Model $record): void {
                try {
                    $document = app(SyncFiscalDocumentStatus::class)(self::documentOf($record));
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
            ->visible(fn (Model $record): bool => self::tenantUsesFiscal()
                && self::documentOf($record)?->status === FiscalDocumentStatus::Issued
                && filled(self::documentOf($record)->provider_id))
            ->action(function (Model $record, Component $livewire): void {
                $document = self::documentOf($record);
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

    /** Teto de vendas por rodada nas ações em lote (uma chamada HTTP por venda). */
    public const BATCH_LIMIT = 25;

    /**
     * "Enviar NFC-e" em lote: cada venda segue as mesmas regras do botão individual
     * (IssueFiscalDocument: bloqueios, lock, número reservado). Pula com o motivo o que não puder
     * e uma falha não interrompe as outras.
     */
    public static function issueInBatch(): BulkAction
    {
        return BulkAction::make('issueFiscalDocumentsInBatch')
            ->label('Enviar NFC-e')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->authorize('emit_fiscal_document')
            ->visible(fn (): bool => self::tenantUsesFiscal())
            ->requiresConfirmation()
            ->modalHeading('Enviar NFC-e das vendas selecionadas')
            ->modalDescription('Envia só as que podem receber nota (sem nota, com falha ou nota cancelada). As demais são puladas, com o motivo. Máximo de '.self::BATCH_LIMIT.' envios por vez.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                // Até BATCH_LIMIT chamadas HTTP em uma request: sem isto uma rodada lenta é morta no meio
                // (hospedagem compartilhada) e deixa envio ambíguo. Silenciado se a hospedagem proibir.
                @set_time_limit(300);
                $records->load('currentFiscalDocument');

                $done = 0;
                $skipped = [];
                $failed = [];
                $attempts = 0;

                foreach ($records as $sale) {
                    /** @var Sale $sale */
                    if ($attempts >= self::BATCH_LIMIT) {
                        $skipped[] = "{$sale->displayNumber()}: limite de ".self::BATCH_LIMIT.' envios por vez; repita para o restante.';

                        continue;
                    }

                    if ($sale->status !== SaleStatus::Finalized || $sale->currentFiscalDocument?->isLive()) {
                        $skipped[] = "{$sale->displayNumber()}: ".($sale->status !== SaleStatus::Finalized ? 'venda não finalizada.' : 'já tem NFC-e emitida ou em andamento.');

                        continue;
                    }

                    $attempts++;

                    try {
                        $document = app(IssueFiscalDocument::class)($sale, FiscalDocumentDecision::Manual, auth()->user());

                        if (in_array($document->status, [FiscalDocumentStatus::Processing, FiscalDocumentStatus::Issued], true)) {
                            $done++;
                        } else {
                            $failed[] = "{$sale->displayNumber()}: ".($document->error_message ?: 'não foi enviada.');
                        }
                    } catch (FiscalDocumentException $exception) {
                        $failed[] = "{$sale->displayNumber()}: {$exception->getMessage()}";
                    } catch (Throwable $exception) {
                        // Erro inesperado numa venda não derruba a rodada nem perde o resumo das outras.
                        report($exception);
                        $failed[] = "{$sale->displayNumber()}: erro inesperado (registrado no log).";
                    }

                    usleep(150_000);
                }

                self::notifyBatch('NFC-e', $done, 'enviada(s)', $skipped, $failed);
            });
    }

    /**
     * "Atualizar status" em lote: consulta a NFE.io das notas com identificador (mesmas regras do botão individual).
     */
    public static function refreshInBatch(): BulkAction
    {
        return BulkAction::make('refreshFiscalDocumentsInBatch')
            ->label('Atualizar status da NFC-e')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize('emit_fiscal_document')
            ->visible(fn (): bool => self::tenantUsesFiscal())
            ->requiresConfirmation()
            ->modalHeading('Atualizar o status das NFC-e selecionadas')
            ->modalDescription('Consulta a NFE.io das vendas que já têm nota enviada. As demais são puladas, com o motivo. Máximo de '.self::BATCH_LIMIT.' consultas por vez.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                @set_time_limit(300);
                $records->load('currentFiscalDocument');

                $done = 0;
                $skipped = [];
                $failed = [];
                $attempts = 0;

                foreach ($records as $sale) {
                    /** @var Sale $sale */
                    $document = $sale->currentFiscalDocument;

                    if ($document === null || blank($document->provider_id) || $document->status === FiscalDocumentStatus::Cancelled) {
                        $skipped[] = "{$sale->displayNumber()}: sem NFC-e para consultar.";

                        continue;
                    }

                    if ($attempts >= self::BATCH_LIMIT) {
                        $skipped[] = "{$sale->displayNumber()}: limite de ".self::BATCH_LIMIT.' consultas por vez; repita para o restante.';

                        continue;
                    }

                    $attempts++;

                    try {
                        app(SyncFiscalDocumentStatus::class)($document);
                        $done++;
                    } catch (FiscalDocumentException $exception) {
                        $failed[] = "{$sale->displayNumber()}: {$exception->getMessage()}";
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed[] = "{$sale->displayNumber()}: erro inesperado (registrado no log).";
                    }

                    usleep(150_000);
                }

                self::notifyBatch('Status', $done, 'atualizado(s)', $skipped, $failed);
            });
    }

    /**
     * @param  list<string>  $skipped
     * @param  list<string>  $failed
     */
    private static function notifyBatch(string $subject, int $done, string $verb, array $skipped, array $failed): void
    {
        $lines = [];

        if ($skipped !== []) {
            $lines[] = 'Puladas: '.implode(' · ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' …' : '');
        }

        if ($failed !== []) {
            $lines[] = 'Falhas: '.implode(' · ', array_slice($failed, 0, 5)).(count($failed) > 5 ? ' …' : '');
        }

        $notification = Notification::make()
            ->title("{$subject}: {$done} {$verb}".($skipped !== [] ? ', '.count($skipped).' pulada(s)' : '').($failed !== [] ? ', '.count($failed).' com falha' : ''))
            ->body($lines === [] ? null : implode("\n", $lines));

        ($failed === [] ? ($done > 0 ? $notification->success() : $notification->warning()) : $notification->danger())->send();
    }
}

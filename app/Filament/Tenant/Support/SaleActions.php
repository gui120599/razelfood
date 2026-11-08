<?php

namespace App\Filament\Tenant\Support;

use App\Actions\Sales\CancelSaleWithFiscalDocument;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Filament\Tenant\Pages\Sales\NewSale;
use App\Models\Sale;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Ações de venda reaproveitadas na lista e no detalhe (mesmo padrão de
 * OrderStatusActions): "Cancelar venda" exige `manage_sales` e um motivo.
 */
class SaleActions
{
    public static function cancel(): Action
    {
        return Action::make('cancelSale')
            ->label('Cancelar venda')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->authorize('manage_sales')
            ->visible(fn (Sale $record): bool => $record->status->canBeCancelled() && ! $record->hasPendingCancellation())
            ->requiresConfirmation()
            ->modalHeading('Cancelar venda')
            ->modalDescription(fn (Sale $record): string => $record->hasLiveFiscalDocument()
                ? 'Esta venda tem NFC-e: o cancelamento da nota é pedido à NFE.io e a venda é cancelada sozinha quando ele for confirmado. Os pedidos voltam para "Contas a fechar" e o estoque dos itens avulsos é devolvido nesse momento.'
                : 'Os pedidos voltam para "Contas a fechar" e o estoque dos itens avulsos é devolvido.')
            ->schema([
                Textarea::make('note')
                    ->label('Motivo do cancelamento')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Sale $record, array $data): void {
                try {
                    ['pending' => $pending] = app(CancelSaleWithFiscalDocument::class)($record, $data['note'], auth()->user());
                } catch (SaleException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                $pending
                    ? Notification::make()->title('Cancelamento solicitado: a venda será cancelada quando a NFE.io confirmar o cancelamento da NFC-e')->success()->send()
                    : Notification::make()->title('Venda cancelada')->success()->send();
            });
    }

    /**
     * Desfaz o cancelamento pendente quando a NFE.io não cancelou a nota (ou o operador desistiu):
     * a venda segue Finalizada e as ações de cancelar voltam a aparecer.
     */
    public static function discardPendingCancellation(): Action
    {
        return Action::make('discardPendingCancellation')
            ->label('Desfazer cancelamento pendente')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->authorize('manage_sales')
            ->visible(fn (Sale $record): bool => $record->status === SaleStatus::Finalized && $record->hasPendingCancellation())
            ->requiresConfirmation()
            ->modalHeading('Desfazer cancelamento pendente')
            ->modalDescription('A venda deixa de aguardar o cancelamento da NFC-e. Se a NFE.io ainda cancelar a nota depois, a venda não será cancelada sozinha.')
            ->action(function (Sale $record): void {
                app(CancelSaleWithFiscalDocument::class)->discardPending($record);

                Notification::make()->title('Cancelamento pendente desfeito')->success()->send();
            });
    }

    /**
     * Retoma um rascunho (venda aberta) na tela Nova Venda.
     */
    public static function resume(): Action
    {
        return Action::make('resume')
            ->label('Retomar')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('warning')
            ->visible(fn (Sale $record): bool => $record->status === SaleStatus::Open && NewSale::canAccess())
            ->url(fn (Sale $record): string => NewSale::getUrl(['sale' => $record->id]));
    }
}

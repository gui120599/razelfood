<?php

namespace App\Filament\Tenant\Support;

use App\Actions\Sales\CancelSale;
use App\Exceptions\SaleException;
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
            ->visible(fn (Sale $record): bool => $record->status->canBeCancelled())
            ->requiresConfirmation()
            ->modalHeading('Cancelar venda')
            ->modalDescription('Os pedidos voltam para "Contas a fechar" e o estoque dos itens avulsos é devolvido.')
            ->schema([
                Textarea::make('note')
                    ->label('Motivo do cancelamento')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Sale $record, array $data): void {
                try {
                    app(CancelSale::class)($record, $data['note'], auth()->user());
                } catch (SaleException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Venda cancelada')->success()->send();
            });
    }
}

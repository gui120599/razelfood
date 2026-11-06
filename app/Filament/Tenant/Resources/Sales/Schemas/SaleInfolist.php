<?php

namespace App\Filament\Tenant\Resources\Sales\Schemas;

use App\Enums\FiscalDocumentStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Filament\Tenant\Resources\Orders\OrderResource;
use App\Filament\Tenant\Support\FiscalDocumentActions;
use App\Models\Sale;
use App\Models\SaleOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Venda')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('number')
                            ->label('Número')
                            ->formatStateUsing(fn (Sale $record): string => $record->displayNumber()),
                        TextEntry::make('status')
                            ->label('Situação')
                            ->badge()
                            ->formatStateUsing(fn (SaleStatus $state): string => $state->label())
                            ->color(fn (SaleStatus $state): string => $state->color()),
                        TextEntry::make('finalized_at')
                            ->label('Finalizada em')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('finalizedBy.name')
                            ->label('Fechada por')
                            ->placeholder('—'),
                    ]),
                ]),
            Section::make('Documento fiscal (NFC-e)')
                ->visible(fn (): bool => FiscalDocumentActions::tenantUsesFiscal())
                ->extraAttributes(fn (Sale $record): array => ($record->currentFiscalDocument?->shouldPoll() || $record->isAwaitingPendingCancellation())
                    ? ['wire:poll.5s' => 'pollFiscalDocument']
                    : [])
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('currentFiscalDocument.status')
                            ->label('Situação')
                            ->badge()
                            ->placeholder('Ainda não emitida')
                            ->formatStateUsing(fn (FiscalDocumentStatus $state): string => $state->label())
                            ->color(fn (FiscalDocumentStatus $state): string => $state->color()),
                        TextEntry::make('currentFiscalDocument.number')
                            ->label('Número / série')
                            ->formatStateUsing(fn (Sale $record): string => $record->currentFiscalDocument->number.' / '.$record->currentFiscalDocument->series)
                            ->placeholder('—'),
                        TextEntry::make('currentFiscalDocument.environment')
                            ->label('Ambiente')
                            ->formatStateUsing(fn ($state): string => $state->label())
                            ->placeholder('—'),
                        TextEntry::make('currentFiscalDocument.authorized_at')
                            ->label('Autorizada em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('currentFiscalDocument.error_message')
                            ->label('Problema')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (Sale $record): bool => filled($record->currentFiscalDocument?->error_message)),
                        TextEntry::make('currentFiscalDocument.buyer_omission_reason')
                            ->label('Consumidor')
                            ->columnSpanFull()
                            ->visible(fn (Sale $record): bool => filled($record->currentFiscalDocument?->buyer_omission_reason)),
                    ]),
                ]),
            Section::make('Cancelamento aguardando a NFC-e')
                ->description('A NFE.io ainda precisa confirmar o cancelamento da NFC-e. Assim que confirmar, a venda é cancelada sozinha com o motivo abaixo.')
                ->visible(fn (Sale $record): bool => $record->hasPendingCancellation() && $record->status === SaleStatus::Finalized)
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('pending_cancellation_at')->label('Solicitado em')->dateTime('d/m/Y H:i'),
                        TextEntry::make('pending_cancellation_note')->label('Motivo')->placeholder('—'),
                    ]),
                ]),
            Section::make('Cancelamento')
                ->visible(fn (Sale $record): bool => $record->status === SaleStatus::Cancelled)
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('cancelled_at')->label('Cancelada em')->dateTime('d/m/Y H:i'),
                        TextEntry::make('cancelledBy.name')->label('Cancelada por')->placeholder('—'),
                        TextEntry::make('cancellation_note')->label('Motivo')->placeholder('—'),
                    ]),
                ]),
            Section::make('Pedidos')
                ->description('Pedidos cobrados por esta venda, como estavam na época (o registro permanece mesmo se a venda for cancelada).')
                ->visible(fn (Sale $record): bool => $record->saleOrders->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('saleOrders')
                        ->label('')
                        ->schema([
                            TextEntry::make('order_label')
                                ->label('Pedido')
                                ->url(fn (SaleOrder $record): ?string => $record->order_id !== null && OrderResource::canAccess()
                                    ? OrderResource::getUrl('view', ['record' => $record->order_id])
                                    : null),
                            TextEntry::make('client_name')->label('Cliente')->placeholder('Sem cliente'),
                            TextEntry::make('order_status')
                                ->label('Status na venda')
                                ->badge()
                                ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                                ->color(fn (OrderStatus $state) => $state->color()),
                            TextEntry::make('order_total')->label('Total')->money('BRL'),
                        ])
                        ->columns(4),
                ]),
            Section::make('Itens avulsos')
                ->visible(fn (Sale $record): bool => $record->items->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            TextEntry::make('product_name')->label('Produto'),
                            TextEntry::make('quantity')->label('Qtd.'),
                            TextEntry::make('unit_price')->label('Preço')->money('BRL'),
                            TextEntry::make('total')->label('Total')->money('BRL'),
                        ])
                        ->columns(4),
                ]),
            Section::make('Pagamentos recebidos')
                ->schema([
                    RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            TextEntry::make('payment_option_name')->label('Forma'),
                            TextEntry::make('amount')->label('Recebido')->money('BRL'),
                            TextEntry::make('change_amount')->label('Troco')->money('BRL'),
                            TextEntry::make('card_brand')->label('Bandeira')->placeholder('—'),
                            TextEntry::make('authorization_code')->label('Autorização')->placeholder('—'),
                        ])
                        ->columns(5),
                ]),
            Section::make('Valores')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('items_total')->label('Itens')->money('BRL'),
                        TextEntry::make('discount_total')->label('Desconto (já nos itens)')->money('BRL'),
                        TextEntry::make('delivery_fee')->label('Taxa de entrega')->money('BRL'),
                        TextEntry::make('total')->label('Total')->money('BRL')->weight('bold'),
                    ]),
                ]),
        ]);
    }
}

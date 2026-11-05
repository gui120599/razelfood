<?php

namespace App\Filament\Tenant\Resources\Sales\Schemas;

use App\Enums\OrderStatus;
use App\Enums\SaleStatus;
use App\Filament\Tenant\Resources\Orders\OrderResource;
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

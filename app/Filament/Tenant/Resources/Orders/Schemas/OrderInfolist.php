<?php

namespace App\Filament\Tenant\Resources\Orders\Schemas;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Filament\Support\InputMasks;
use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('id')
                            ->label('Número')
                            ->formatStateUsing(fn (Order $record) => "#{$record->id}"),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                            ->color(fn (OrderStatus $state) => $state->color()),
                        TextEntry::make('origin')
                            ->label('Origem')
                            ->badge()
                            ->formatStateUsing(fn (OrderOrigin $state) => $state->label()),
                        TextEntry::make('client.name')
                            ->label('Cliente')
                            ->placeholder('Sem cliente'),
                        TextEntry::make('client.phone')
                            ->label('Telefone')
                            ->formatStateUsing(fn (?string $state): ?string => InputMasks::formatPhone($state))
                            ->placeholder('—'),
                        TextEntry::make('client.cpf')
                            ->label('CPF')
                            ->formatStateUsing(fn (?string $state): ?string => InputMasks::formatCpf($state))
                            ->visible(fn (?string $state): bool => filled($state)),
                        TextEntry::make('delivery_address')
                            ->label('Endereço')
                            ->placeholder('Retirada no local'),
                        TextEntry::make('deliveryOption.name')
                            ->label('Modalidade')
                            ->placeholder('Retirada no local'),
                        TextEntry::make('deliveryZone.name')
                            ->label('Setor de entrega')
                            ->placeholder('—'),
                        TextEntry::make('is_unlisted_neighborhood')
                            ->label('Bairro fora da área mapeada')
                            ->badge()
                            ->formatStateUsing(fn (bool $state) => $state ? 'Confirmar viabilidade' : 'Não')
                            ->color(fn (bool $state) => $state ? 'warning' : 'gray')
                            ->visible(fn (Order $record) => $record->requiresDelivery()),
                    ]),
                    TextEntry::make('notes')
                        ->label('Observação do pedido')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Section::make('Pagamento')
                ->schema([
                    RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            TextEntry::make('payment_option_name')->label('Forma'),
                            TextEntry::make('amount')->label('Valor')->money('BRL'),
                            TextEntry::make('change_for')->label('Troco para')->money('BRL')->placeholder('—'),
                        ])
                        ->columns(3),
                ]),
            Section::make('Valores')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('items_total')->label('Subtotal')->money('BRL'),
                        TextEntry::make('discount_total')->label('Desconto')->money('BRL'),
                        TextEntry::make('delivery_fee')->label('Entrega')->money('BRL'),
                        TextEntry::make('grand_total')->label('Total')->money('BRL')->weight('bold'),
                    ]),
                ]),
            Section::make('Cancelamento')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('cancellation_reason')
                            ->label('Motivo')
                            ->formatStateUsing(fn ($state) => $state->label()),
                        TextEntry::make('cancelledBy.name')
                            ->label('Cancelado por')
                            ->placeholder('Cliente / não identificado'),
                    ]),
                ])
                ->visible(fn (Order $record) => $record->status === OrderStatus::Cancelled),
            Section::make('Linha do tempo')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('opened_at')->label('Iniciado')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('accepted_at')->label('Aceito')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('preparing_at')->label('Em preparo')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('ready_at')->label('Pronto')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('in_transit_at')->label('Em transporte')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('delivered_at')->label('Entregue')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('finished_at')->label('Finalizado')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('Cancelado')->dateTime('d/m/Y H:i')->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}

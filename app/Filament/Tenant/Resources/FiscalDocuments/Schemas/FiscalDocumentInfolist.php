<?php

namespace App\Filament\Tenant\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Filament\Tenant\Resources\Sales\SaleResource;
use App\Models\FiscalDocument;
use App\Models\FiscalWebhook;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FiscalDocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Documento')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('number')
                            ->label('Nº / série')
                            ->formatStateUsing(fn (FiscalDocument $record): string => "{$record->number} / {$record->series}"),
                        TextEntry::make('status')
                            ->label('Situação')
                            ->badge()
                            ->formatStateUsing(fn (FiscalDocumentStatus $state): string => $state->label())
                            ->color(fn (FiscalDocumentStatus $state): string => $state->color()),
                        TextEntry::make('environment')
                            ->label('Ambiente')
                            ->formatStateUsing(fn (FiscalEnvironment $state): string => $state->label()),
                        TextEntry::make('decision')
                            ->label('Emissão')
                            ->formatStateUsing(fn (FiscalDocumentDecision $state): string => $state->label()),
                        TextEntry::make('sale.number')
                            ->label('Venda')
                            ->formatStateUsing(fn (FiscalDocument $record): string => $record->sale?->displayNumber() ?? '—')
                            ->url(fn (FiscalDocument $record): ?string => $record->sale !== null && SaleResource::canView($record->sale)
                                ? SaleResource::getUrl('view', ['record' => $record->sale])
                                : null),
                        TextEntry::make('sent_at')->label('Enviada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('authorized_at')->label('Autorizada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('Cancelada em')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('cancel_requested_at')->label('Cancelamento solicitado em')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('provider_status')->label('Status na NFE.io')->placeholder('—'),
                        TextEntry::make('provider_id')->label('ID na NFE.io')->placeholder('—')->copyable(),
                        TextEntry::make('error_message')
                            ->label('Problema')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (FiscalDocument $record): bool => filled($record->error_message)),
                        TextEntry::make('buyer_omission_reason')
                            ->label('Consumidor')
                            ->columnSpanFull()
                            ->visible(fn (FiscalDocument $record): bool => filled($record->buyer_omission_reason)),
                    ]),
                ]),
            Section::make('Itens da nota')
                ->description('Como foram enviados à NFE.io.')
                ->visible(fn (FiscalDocument $record): bool => $record->items->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            TextEntry::make('description')->label('Descrição'),
                            TextEntry::make('ncm')->label('NCM')->placeholder('—'),
                            TextEntry::make('cfop')->label('CFOP')->placeholder('—'),
                            TextEntry::make('csosn')->label('CSOSN')->placeholder('—'),
                            TextEntry::make('quantity')->label('Qtd.'),
                            TextEntry::make('unit_amount')->label('Unitário')->money('BRL'),
                            TextEntry::make('total_amount')->label('Itens')->money('BRL'),
                            TextEntry::make('others_amount')->label('Adicionais e frete')->money('BRL'),
                        ])
                        ->columns(4),
                ]),
            Section::make('Avisos da NFE.io (webhook)')
                ->collapsed()
                ->visible(fn (FiscalDocument $record): bool => $record->webhooks()->exists())
                ->schema([
                    RepeatableEntry::make('webhooks')
                        ->label('')
                        ->state(fn (FiscalDocument $record) => $record->webhooks()->limit(20)->get())
                        ->schema([
                            TextEntry::make('received_at')->label('Recebido em')->dateTime('d/m/Y H:i:s'),
                            TextEntry::make('event')->label('Evento')->placeholder('—'),
                            IconEntry::make('signature_valid')->label('Assinatura válida')->boolean(),
                            TextEntry::make('outcome')
                                ->label('Resultado')
                                ->formatStateUsing(fn (?string $state): string => match ($state) {
                                    FiscalWebhook::OUTCOME_APPLIED => 'Aplicado',
                                    FiscalWebhook::OUTCOME_UNCHANGED => 'Sem mudança',
                                    FiscalWebhook::OUTCOME_UNKNOWN_DOCUMENT => 'Documento desconhecido',
                                    FiscalWebhook::OUTCOME_FEATURE_OFF => 'Recurso desligado',
                                    FiscalWebhook::OUTCOME_REJECTED => 'Rejeitado (assinatura)',
                                    FiscalWebhook::OUTCOME_ERROR => 'Erro',
                                    default => '—',
                                }),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}

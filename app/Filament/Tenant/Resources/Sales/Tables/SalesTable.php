<?php

namespace App\Filament\Tenant\Resources\Sales\Tables;

use App\Enums\FiscalDocumentStatus;
use App\Enums\SaleStatus;
use App\Filament\Tenant\Support\FiscalDocumentActions;
use App\Filament\Tenant\Support\SaleActions;
use App\Models\Sale;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['payments', 'finalizedBy', 'currentFiscalDocument']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('#')
                    ->formatStateUsing(fn (Sale $record): string => $record->displayNumber())
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (SaleStatus $state): string => $state->label())
                    ->color(fn (SaleStatus $state): string => $state->color()),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('currentFiscalDocument.status')
                    ->label('NFC-e')
                    ->badge()
                    ->placeholder('—')
                    ->visible(fn (): bool => FiscalDocumentActions::tenantUsesFiscal())
                    ->formatStateUsing(fn (FiscalDocumentStatus $state): string => $state->label())
                    ->color(fn (FiscalDocumentStatus $state): string => $state->color()),
                TextColumn::make('payments_summary')
                    ->label('Pagamento')
                    ->state(fn (Sale $record): string => $record->payments->pluck('payment_option_name')->unique()->implode(', '))
                    ->placeholder('—'),
                TextColumn::make('finalizedBy.name')
                    ->label('Fechada por')
                    ->placeholder('—'),
                TextColumn::make('finalized_at')
                    ->label('Finalizada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('fiscal_status')
                    ->label('Situação da NFC-e')
                    ->visible(fn (): bool => FiscalDocumentActions::tenantUsesFiscal())
                    ->options(['none' => 'Sem nota'] + collect(FiscalDocumentStatus::cases())->mapWithKeys(
                        fn (FiscalDocumentStatus $status): array => [$status->value => $status->label()]
                    )->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->fiscalStatus($data['value']) : $query),
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(SaleStatus::cases())->mapWithKeys(
                        fn (SaleStatus $status): array => [$status->value => $status->label()]
                    )),
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('finalized_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('finalized_at', '<=', $date))),
            ])
            ->recordActions([
                SaleActions::resume(),
                ViewAction::make(),
                SaleActions::cancel(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    FiscalDocumentActions::issueInBatch(),
                    FiscalDocumentActions::refreshInBatch(),
                ]),
            ]);
    }
}

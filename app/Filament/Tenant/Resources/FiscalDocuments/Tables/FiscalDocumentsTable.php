<?php

namespace App\Filament\Tenant\Resources\FiscalDocuments\Tables;

use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Filament\Tenant\Resources\Sales\SaleResource;
use App\Filament\Tenant\Support\FiscalDocumentActions;
use App\Models\FiscalDocument;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FiscalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('sale.currentFiscalDocument'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Nº / série')
                    ->formatStateUsing(fn (FiscalDocument $record): string => "{$record->number} / {$record->series}")
                    ->sortable(),
                TextColumn::make('sale.number')
                    ->label('Venda')
                    ->formatStateUsing(fn (FiscalDocument $record): string => $record->sale?->displayNumber() ?? '—')
                    ->url(fn (FiscalDocument $record): ?string => $record->sale !== null && SaleResource::canView($record->sale)
                        ? SaleResource::getUrl('view', ['record' => $record->sale])
                        : null),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn (FiscalDocumentStatus $state): string => $state->label())
                    ->color(fn (FiscalDocumentStatus $state): string => $state->color()),
                TextColumn::make('environment')
                    ->label('Ambiente')
                    ->formatStateUsing(fn (FiscalEnvironment $state): string => $state->label()),
                TextColumn::make('sent_at')
                    ->label('Enviada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('authorized_at')
                    ->label('Autorizada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('decision')
                    ->label('Emissão')
                    ->formatStateUsing(fn (FiscalDocumentDecision $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_message')
                    ->label('Problema')
                    ->limit(60)
                    ->tooltip(fn (FiscalDocument $record): ?string => $record->error_message)
                    ->color('danger')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(FiscalDocumentStatus::cases())->mapWithKeys(
                        fn (FiscalDocumentStatus $status): array => [$status->value => $status->label()]
                    )->all()),
                SelectFilter::make('environment')
                    ->label('Ambiente')
                    ->options(collect(FiscalEnvironment::cases())->mapWithKeys(
                        fn (FiscalEnvironment $environment): array => [$environment->value => $environment->label()]
                    )->all()),
                Filter::make('unused_numbers')
                    ->label('Números não usados')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->unusedNumbers()),
                Filter::make('period')
                    ->label('Criada entre')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                FiscalDocumentActions::issue(),
                FiscalDocumentActions::refresh(),
                FiscalDocumentActions::cancel(),
                FiscalDocumentActions::danfe(),
            ]);
    }
}

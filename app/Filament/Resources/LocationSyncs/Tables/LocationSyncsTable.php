<?php

namespace App\Filament\Resources\LocationSyncs\Tables;

use App\Enums\LocationSyncStatus;
use App\Models\LocationSync;
use App\Services\Address\LocationSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class LocationSyncsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['state', 'city']))
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('state.uf')
                    ->label('UF'),
                TextColumn::make('city.name')
                    ->label('Cidade'),
                TextColumn::make('range')
                    ->label('Faixa de CEP')
                    ->state(fn (LocationSync $record): string => str_pad((string) $record->cep_start, 8, '0', STR_PAD_LEFT)
                        .' – '
                        .str_pad((string) $record->cep_end, 8, '0', STR_PAD_LEFT)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (LocationSyncStatus $state): string => $state->label())
                    ->color(fn (LocationSyncStatus $state): string => match ($state) {
                        LocationSyncStatus::Pending => 'gray',
                        LocationSyncStatus::Processing => 'info',
                        LocationSyncStatus::Completed => 'success',
                        LocationSyncStatus::Paused => 'warning',
                        LocationSyncStatus::Failed => 'danger',
                    }),
                TextColumn::make('progress')
                    ->label('Progresso')
                    ->state(fn (LocationSync $record): string => $record->total_ceps > 0
                        ? "{$record->ceps_processed} / {$record->total_ceps} (".round($record->ceps_processed / $record->total_ceps * 100).'%)'
                        : '—'),
                TextColumn::make('neighborhoods_created')
                    ->label('Bairros novos'),
                TextColumn::make('errors_count')
                    ->label('Erros')
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('started_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('finished_at')
                    ->label('Fim')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('pause')
                    ->label('Pausar')
                    ->icon(Heroicon::OutlinedPause)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (LocationSync $record): bool => $record->status === LocationSyncStatus::Processing)
                    ->action(fn (LocationSync $record) => app(LocationSyncService::class)->pauseSync($record)),
                Action::make('resume')
                    ->label('Retomar')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('success')
                    ->visible(fn (LocationSync $record): bool => in_array($record->status, [
                        LocationSyncStatus::Paused,
                        LocationSyncStatus::Failed,
                    ]) || $record->isStuck())
                    ->action(fn (LocationSync $record) => app(LocationSyncService::class)->resumeSync($record)),
                Action::make('viewErrors')
                    ->label('Ver erros')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('gray')
                    ->modalHeading('Erros da sincronização')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->visible(fn (LocationSync $record): bool => $record->errors_count > 0 || $record->logs()->exists())
                    ->schema(fn (LocationSync $record): array => [
                        Placeholder::make('logs')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => new HtmlString(nl2br(e($record->logs()->latest()->limit(200)->get()
                                ->map(fn ($log) => "CEP {$log->cep} — {$log->type}: {$log->message}")
                                ->implode("\n"))))),
                    ]),
            ]);
    }
}

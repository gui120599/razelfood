<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Enums\TenantStatus;
use App\Filament\Support\InputMasks;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('plan'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TenantStatus $state) => $state->label())
                    ->color(fn (TenantStatus $state) => match ($state) {
                        TenantStatus::Active => 'success',
                        TenantStatus::Suspended => 'warning',
                        TenantStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('plan.name')
                    ->label('Plano')
                    ->badge(),
                TextColumn::make('whatsapp_number')
                    ->label('WhatsApp')
                    ->formatStateUsing(fn (?string $state): ?string => InputMasks::formatPhone($state)),
                TextColumn::make('users_count')
                    ->label('Usuários')
                    ->counts('users'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(
                        fn (TenantStatus $status) => [$status->value => $status->label()]
                    )),
                SelectFilter::make('plan_id')
                    ->label('Plano')
                    ->relationship('plan', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}

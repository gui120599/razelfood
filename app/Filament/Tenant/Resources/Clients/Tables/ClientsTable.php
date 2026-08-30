<?php

namespace App\Filament\Tenant\Resources\Clients\Tables;

use App\Filament\Support\InputMasks;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->formatStateUsing(fn (?string $state): ?string => InputMasks::formatPhone($state))
                    ->searchable(),
                TextColumn::make('neighborhood')
                    ->label('Bairro')
                    ->placeholder('—'),
                TextColumn::make('city')
                    ->label('Cidade')
                    ->placeholder('—'),
                TextColumn::make('orders_count')
                    ->label('Pedidos')
                    ->counts('orders')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

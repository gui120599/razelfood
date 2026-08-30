<?php

namespace App\Filament\Resources\Features\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FeaturesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('key')
                    ->label('Chave')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_available')
                    ->label('Disponível')
                    ->boolean(),
                TextColumn::make('plans_count')
                    ->label('Planos')
                    ->counts('plans'),
                TextColumn::make('display_order')
                    ->label('Ordem')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_available')
                    ->label('Disponível'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

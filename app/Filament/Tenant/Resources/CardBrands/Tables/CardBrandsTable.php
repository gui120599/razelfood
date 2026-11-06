<?php

namespace App\Filament\Tenant\Resources\CardBrands\Tables;

use App\Enums\CardBrand as CardBrandEnum;
use App\Filament\Support\InputMasks;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardBrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('brand')
                    ->label('Bandeira na nota')
                    ->formatStateUsing(fn (CardBrandEnum $state): string => $state->label()),
                TextColumn::make('acquirer_cnpj')
                    ->label('CNPJ da credenciadora')
                    ->formatStateUsing(fn (?string $state): ?string => InputMasks::formatCnpj($state))
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

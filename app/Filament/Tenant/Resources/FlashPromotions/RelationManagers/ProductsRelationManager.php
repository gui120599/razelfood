<?php

namespace App\Filament\Tenant\Resources\FlashPromotions\RelationManagers;

use App\Filament\Support\InputMasks;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Produtos na promoção';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Produto'),
                TextColumn::make('pivot.promotional_price')
                    ->label('Preço promocional')
                    ->money('BRL'),
                TextColumn::make('pivot.total_quantity')
                    ->label('Sub-limite')
                    ->placeholder('—'),
                TextColumn::make('pivot.sold_quantity')
                    ->label('Vendidos'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        InputMasks::money(
                            TextInput::make('promotional_price')
                                ->label('Preço promocional')
                                ->prefix('R$')
                                ->required()
                        ),
                        TextInput::make('total_quantity')
                            ->label('Sub-limite de unidades')
                            ->numeric()
                            ->helperText('Vazio = sem sub-limite próprio, só o teto geral da promoção.'),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        InputMasks::money(
                            TextInput::make('promotional_price')
                                ->label('Preço promocional')
                                ->prefix('R$')
                                ->required()
                        ),
                        TextInput::make('total_quantity')
                            ->label('Sub-limite de unidades')
                            ->numeric(),
                    ]),
                DetachAction::make(),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }
}

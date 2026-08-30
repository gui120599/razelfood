<?php

namespace App\Filament\Tenant\Resources\Products\RelationManagers;

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

/**
 * Adicionais disponíveis para este produto (RN-46). `pivot.price` sobrescreve
 * `addons.price` só pra este vínculo — vazio = usa o preço base. `pivot.max_quantity`
 * limita porções por linha de carrinho — vazio = sem limite.
 */
class AddonsRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $title = 'Adicionais do produto';

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
                    ->label('Adicional'),
                TextColumn::make('price')
                    ->label('Preço base')
                    ->money('BRL'),
                TextColumn::make('pivot.price')
                    ->label('Preço neste produto')
                    ->money('BRL')
                    ->placeholder('— usa o preço base'),
                TextColumn::make('pivot.max_quantity')
                    ->label('Máx. por pedido')
                    ->placeholder('sem limite'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        InputMasks::money(
                            TextInput::make('price')
                                ->label('Preço neste produto (opcional)')
                                ->prefix('R$')
                                ->helperText('Vazio = usa o preço base do adicional.')
                        ),
                        TextInput::make('max_quantity')
                            ->label('Quantidade máxima por pedido')
                            ->numeric()
                            ->helperText('Vazio = sem limite.'),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        InputMasks::money(
                            TextInput::make('price')
                                ->label('Preço neste produto (opcional)')
                                ->prefix('R$')
                                ->helperText('Vazio = usa o preço base do adicional.')
                        ),
                        TextInput::make('max_quantity')
                            ->label('Quantidade máxima por pedido')
                            ->numeric()
                            ->helperText('Vazio = sem limite.'),
                    ]),
                DetachAction::make(),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }
}

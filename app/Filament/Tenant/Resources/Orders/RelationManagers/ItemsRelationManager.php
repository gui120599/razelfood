<?php

namespace App\Filament\Tenant\Resources\Orders\RelationManagers;

use App\Models\Addon;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Itens do pedido';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('note')
            ->columns([
                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray')
                    ->state(function (OrderItem $record) {
                        $productId = $record->flavors[0] ?? $record->product_id;

                        return Product::withTrashed()->find($productId)?->category?->name;
                    })
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label('Produto')
                    ->state(fn (OrderItem $record) => $record->flavors
                        ? Product::withTrashed()->whereIn('id', $record->flavors)->pluck('name')->implode(' / ')
                        : $record->product?->name ?? 'Produto removido'),
                TextColumn::make('quantity')
                    ->label('Qtd.'),
                TextColumn::make('unit_price')
                    ->label('Preço unit.')
                    ->money('BRL'),
                TextColumn::make('original_unit_price')
                    ->label('Preço original')
                    ->money('BRL'),
                TextColumn::make('addons_display')
                    ->label('Adicionais')
                    ->state(fn (OrderItem $record) => collect($record->addons ?? [])
                        ->map(fn (array $selection) => "{$selection['quantity']}x ".(Addon::withTrashed()->find($selection['addon_id'])?->name ?? 'Adicional removido'))
                        ->implode(' / '))
                    ->placeholder('—'),
                TextColumn::make('addons_total')
                    ->label('Adicionais (R$)')
                    ->money('BRL'),
                TextColumn::make('gifts_display')
                    ->label('Brindes')
                    ->state(fn (OrderItem $record) => collect($record->gifts ?? [])
                        ->map(function (array $gift) {
                            $name = Product::withTrashed()->find($gift['gift_product_id'])?->name ?? 'Brinde removido';

                            return ($gift['accepted'] ?? false) === true
                                ? "🎁 {$gift['quantity']}x {$name}"
                                : "🎁 {$name} (recusado)";
                        })
                        ->implode(' / '))
                    ->placeholder('—'),
                TextColumn::make('note')
                    ->label('Observação')
                    ->placeholder('—'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}

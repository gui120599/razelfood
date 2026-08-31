<?php

namespace App\Filament\Tenant\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A listagem mostra só categorias raiz; subcategoria é gerenciada
            // pelo SubcategoriesRelationManager na página de edição do pai. O
            // filtro fica aqui (não no getEloquentQuery do Resource) para não
            // quebrar a resolução do record em /categories/{subcategoria}/edit.
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('parent_id'))
            ->reorderable('display_order')
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('show_in_menu')
                    ->label('No cardápio')
                    ->boolean(),
                IconColumn::make('show_description_in_menu')
                    ->label('Descrição no cardápio')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('allows_flavors')
                    ->label('Permite sabores')
                    ->boolean(),
                TextColumn::make('products_count')
                    ->label('Produtos')
                    ->counts('products'),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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

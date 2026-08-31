<?php

namespace App\Filament\Tenant\Resources\Products\Tables;

use App\Actions\Products\ReplicateProductsToCategory;
use App\Filament\Tenant\Support\CategoryOptions;
use App\Models\Category;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->reorderable('display_order')
            ->defaultSort('display_order')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Foto')
                    ->disk('public'),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('promotional_price')
                    ->label('Preço promo.')
                    ->money('BRL')
                    ->placeholder('—'),
                IconColumn::make('is_visible')
                    ->label('Visível')
                    ->boolean(),
                TextColumn::make('sales_count')
                    ->label('Vendidos')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Categoria')
                    ->relationship('category', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->excludeAttributes(['sales_count']),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('replicateToCategory')
                        ->label('Replicar para outra categoria')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->modalHeading('Replicar produtos para outra categoria')
                        ->modalDescription('Cria uma cópia de cada produto selecionado na categoria escolhida (os originais continuam onde estão). Os adicionais vinculados vão junto.')
                        ->modalSubmitActionLabel('Replicar')
                        ->authorize(fn (): bool => auth()->user()?->can('create', Product::class) ?? false)
                        ->schema([
                            Select::make('category_id')
                                ->label('Categoria de destino')
                                ->options(fn (): array => CategoryOptions::grouped())
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $target = Category::findOrFail($data['category_id']);
                            $count = app(ReplicateProductsToCategory::class)($records, $target);

                            Notification::make()
                                ->title('Produtos replicados')
                                ->body("{$count} produto(s) copiado(s) para {$target->name}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}

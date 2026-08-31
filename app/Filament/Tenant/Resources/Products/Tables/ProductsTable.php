<?php

namespace App\Filament\Tenant\Resources\Products\Tables;

use App\Actions\Products\AdjustProductsPrice;
use App\Actions\Products\ReplicateProductsToCategory;
use App\Filament\Support\InputMasks;
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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
                    BulkAction::make('adjustPrice')
                        ->label('Ajustar preço')
                        ->icon(Heroicon::OutlinedCurrencyDollar)
                        ->modalHeading('Ajustar preço dos produtos selecionados')
                        ->modalSubmitActionLabel('Aplicar')
                        ->authorizeIndividualRecords('update')
                        ->schema([
                            Select::make('mode')
                                ->label('Tipo de ajuste')
                                ->options([
                                    'set' => 'Definir um valor fixo',
                                    'percent' => 'Porcentagem (%)',
                                    'amount' => 'Valor (R$)',
                                ])
                                ->default('set')
                                ->required()
                                ->live(),
                            Select::make('direction')
                                ->label('Direção')
                                ->options([
                                    'increase' => 'Aumentar',
                                    'decrease' => 'Diminuir',
                                ])
                                ->default('increase')
                                ->required()
                                ->visible(fn (Get $get): bool => in_array($get('mode'), ['percent', 'amount'], true)),
                            InputMasks::money(
                                TextInput::make('value')
                                    ->label(fn (Get $get): string => match ($get('mode')) {
                                        'set' => 'Novo preço',
                                        'percent' => 'Porcentagem',
                                        default => 'Valor',
                                    })
                                    ->prefix(fn (Get $get): ?string => $get('mode') === 'percent' ? null : 'R$')
                                    ->suffix(fn (Get $get): ?string => $get('mode') === 'percent' ? '%' : null)
                                    ->required()
                            ),
                            Toggle::make('apply_to_promotional')
                                ->label('Aplicar também ao preço promocional (quando houver)')
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = app(AdjustProductsPrice::class)(
                                $records,
                                $data['mode'],
                                (float) $data['value'],
                                $data['direction'] ?? 'increase',
                                (bool) ($data['apply_to_promotional'] ?? false),
                            );

                            Notification::make()
                                ->title('Preços atualizados')
                                ->body("{$count} produto(s) atualizado(s).")
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

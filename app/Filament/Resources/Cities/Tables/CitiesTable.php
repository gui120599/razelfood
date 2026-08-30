<?php

namespace App\Filament\Resources\Cities\Tables;

use App\Models\City;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('state'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('state.uf')
                    ->label('UF')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ibge_code')
                    ->label('Código IBGE')
                    ->placeholder('—'),
                TextColumn::make('neighborhoods_count')
                    ->label('Bairros')
                    ->counts('neighborhoods'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->relationship('state', 'name')
                    ->searchable()
                    ->label('Estado'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ]);
    }

    /**
     * Reaproveitado também no header da EditCity (Pages\EditCity).
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (Action $action, City $record): void {
                if ($record->locationSyncs()->exists() || $record->neighborhoods()->exists()) {
                    Notification::make()
                        ->title('Não é possível excluir esta cidade')
                        ->body("Existem {$record->neighborhoods()->count()} bairro(s) e/ou sincronizações registradas para ela. Remova ou reatribua os vínculos primeiro.")
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }
}

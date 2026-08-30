<?php

namespace App\Filament\Resources\States\Tables;

use App\Models\State;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatesTable
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
                TextColumn::make('uf')
                    ->label('UF')
                    ->badge(),
                TextColumn::make('ibge_code')
                    ->label('Código IBGE')
                    ->placeholder('—'),
                TextColumn::make('cities_count')
                    ->label('Cidades')
                    ->counts('cities'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ]);
    }

    /**
     * Reaproveitado também no header da EditState (Pages\EditState) — evita
     * duplicar a checagem de vínculos em dois lugares.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (Action $action, State $record): void {
                if ($record->locationSyncs()->exists() || $record->cities()->exists()) {
                    Notification::make()
                        ->title('Não é possível excluir este estado')
                        ->body("Existem {$record->cities()->count()} cidade(s) e/ou sincronizações registradas para ele. Remova ou reatribua os vínculos primeiro.")
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }
}

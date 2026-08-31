<?php

namespace App\Filament\Tenant\Resources\Categories\RelationManagers;

use App\Filament\Tenant\Resources\Categories\CategoryResource;
use App\Filament\Tenant\Resources\Categories\Schemas\CategoryForm;
use App\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubcategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $title = 'Subcategorias';

    /**
     * Hierarquia trava em 1 nível: a página de edição de uma subcategoria não
     * mostra este gerenciador (nada de sub-subcategoria).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->parent_id === null;
    }

    public function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('display_order')
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                IconColumn::make('show_in_menu')
                    ->label('No cardápio')
                    ->boolean(),
                TextColumn::make('flavor_options_summary')
                    ->label('Sabores')
                    ->state(fn (Category $record): string => match (true) {
                        ! $record->allows_flavors => '—',
                        $record->inherit_flavor_options => 'Herda do pai',
                        default => $record->flavorQuantityOptions()->count().' opção(ões)',
                    }),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn (Category $record): string => CategoryResource::getUrl('edit', ['record' => $record])),
                DeleteAction::make(),
            ]);
    }
}

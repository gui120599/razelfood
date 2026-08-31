<?php

namespace App\Filament\Tenant\Resources\Categories\Schemas;

use App\Filament\Tenant\Resources\Categories\RelationManagers\SubcategoriesRelationManager;
use App\Models\Category;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Component;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados da categoria')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->maxLength(255)
                        ->helperText('Aparece abaixo do nome da categoria no cardápio. Ex.: "Serve até 2 pessoas".')
                        ->columnSpanFull(),
                    Toggle::make('show_in_menu')
                        ->label('Exibir no cardápio')
                        ->default(true),
                    Toggle::make('show_description_in_menu')
                        ->label('Exibir descrição no cardápio')
                        ->default(false),
                    Toggle::make('allows_flavors')
                        ->label('Permite sabores (meio a meio)')
                        ->helperText('Depois de salvar, configure as quantidades de sabores disponíveis na aba "Quantidades de sabores".')
                        ->live(),
                    Toggle::make('inherit_flavor_options')
                        ->label('Herdar quantidades de sabores da categoria pai')
                        ->helperText('A subcategoria usa as opções de "Quantidades de sabores" da categoria pai. Desligue para cadastrar as suas próprias.')
                        ->default(true)
                        ->live()
                        ->visible(fn (Get $get, ?Category $record, Component $livewire): bool => self::showInheritToggle($get, $record, $livewire)),
                ]),
        ]);
    }

    /**
     * O toggle de herança só faz sentido para uma subcategoria cujo pai
     * trabalha com sabores e já tem ao menos uma opção cadastrada.
     */
    private static function showInheritToggle(Get $get, ?Category $record, Component $livewire): bool
    {
        if (! $get('allows_flavors')) {
            return false;
        }

        $parent = self::resolveParentCategory($record, $livewire);

        return $parent !== null
            && $parent->allows_flavors
            && $parent->flavorQuantityOptions()->exists();
    }

    private static function resolveParentCategory(?Category $record, Component $livewire): ?Category
    {
        if ($record?->parent_id !== null) {
            return $record?->parent;
        }

        if ($livewire instanceof SubcategoriesRelationManager) {
            $ownerRecord = $livewire->getOwnerRecord();

            return $ownerRecord instanceof Category ? $ownerRecord : null;
        }

        return null;
    }
}

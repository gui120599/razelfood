<?php

namespace App\Filament\Tenant\Resources\Products\Schemas;

use App\Filament\Support\InputMasks;
use App\Models\Category;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados do produto')
                ->columns(2)
                ->schema([
                    Select::make('category_id')
                        ->label('Categoria')
                        ->options(fn (): array => self::categoryOptions())
                        ->searchable()
                        ->required(),
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->columnSpanFull(),
                    FileUpload::make('image_path')
                        ->label('Foto')
                        ->image()
                        ->disk('public')
                        ->directory('produtos')
                        ->visibility('public')
                        ->imageEditor()
                        ->maxSize(2048)
                        ->columnSpanFull(),
                ]),
            Section::make('Preço')
                ->columns(3)
                ->schema([
                    InputMasks::money(
                        TextInput::make('price')
                            ->label('Preço')
                            ->prefix('R$')
                            ->required()
                    ),
                    InputMasks::money(
                        TextInput::make('promotional_price')
                            ->label('Preço promocional')
                            ->prefix('R$')
                    ),
                    DateTimePicker::make('promo_starts_at')
                        ->label('Início da promoção')
                        ->visible(fn (Get $get): bool => filled($get('promotional_price'))),
                    DateTimePicker::make('promo_ends_at')
                        ->label('Fim da promoção')
                        ->visible(fn (Get $get): bool => filled($get('promotional_price'))),
                ]),
            Section::make('Disponibilidade')
                ->columns(3)
                ->schema([
                    Toggle::make('is_visible')
                        ->label('Visível no cardápio')
                        ->default(true),
                    Toggle::make('bestseller_eligible')
                        ->label('Elegível a "mais vendidos"'),
                    Toggle::make('controls_stock')
                        ->label('Controla estoque')
                        ->live(),
                    TextInput::make('stock_quantity')
                        ->label('Quantidade em estoque')
                        ->numeric()
                        ->visible(fn (Get $get): bool => (bool) $get('controls_stock')),
                    Toggle::make('show_when_out_of_stock')
                        ->label('Exibir mesmo sem estoque')
                        ->visible(fn (Get $get): bool => (bool) $get('controls_stock')),
                ]),
        ]);
    }

    /**
     * Opções do select de categoria agrupadas pela categoria pai. Subcategorias
     * de pais diferentes podem ter o mesmo nome — o cabeçalho do grupo (nome do
     * pai) é o que as distingue. Categoria raiz sem subcategoria fica solta no
     * topo, sem grupo.
     *
     * @return array<int|string, string|array<int, string>>
     */
    private static function categoryOptions(): array
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->orderBy('display_order')])
            ->orderBy('display_order')
            ->get();

        $options = [];

        foreach ($roots as $root) {
            if ($root->children->isEmpty()) {
                $options[$root->id] = $root->name;

                continue;
            }

            $group = [$root->id => $root->name];

            foreach ($root->children as $child) {
                $group[$child->id] = $child->name;
            }

            $options[$root->name] = $group;
        }

        return $options;
    }
}

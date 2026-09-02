<?php

namespace App\Filament\Tenant\Resources\Products\RelationManagers;

use App\Models\Product;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Produtos do catálogo oferecidos como brinde grátis quando este produto é
 * comprado (RN-53). Espelha AddonsRelationManager (RelationManager com
 * AttachAction, sem Repeater). `pivot.quantity` = unidades do brinde por
 * unidade do produto principal; `pivot.is_active` liga/desliga a oferta sem
 * remover o vínculo; `pivot.flavor_counts` restringe a quais quantidades de
 * sabores o brinde é oferecido (vazio = todas). O brinde entra no pedido
 * sempre a R$ 0,00.
 *
 * `$inverseRelationship` é obrigatório aqui: gifts() é um self-join
 * (Product ↔ Product), então o Filament não consegue inferir a inversa a
 * partir do nome do model dono — ver .ai/rules/models.md.
 */
class GiftsRelationManager extends RelationManager
{
    protected static string $relationship = 'gifts';

    protected static ?string $inverseRelationship = 'giftedByProducts';

    protected static ?string $title = 'Brindes do produto';

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
                    ->label('Produto brinde'),
                TextColumn::make('pivot.quantity')
                    ->label('Quantidade'),
                IconColumn::make('pivot.is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('pivot.flavor_counts')
                    ->label('Habilitado para')
                    ->formatStateUsing(fn ($state): string => $this->formatFlavorCounts($state))
                    ->placeholder('todas as quantidades'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Adicionar brinde')
                    ->multiple()
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->whereKeyNot($this->getOwnerRecord()->getKey()))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Produto brinde')
                            ->helperText('Você pode escolher vários de uma vez. Os valores abaixo valem para todos os selecionados.'),
                        TextInput::make('quantity')
                            ->label('Quantidade oferecida')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->required()
                            ->helperText('Unidades do brinde por unidade do produto principal.'),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                        CheckboxList::make('flavor_counts')
                            ->label('Habilitar quando o produto for vendido como')
                            ->options(fn (): array => $this->flavorCountOptions())
                            ->visible(fn (): bool => filled($this->flavorCountOptions()))
                            ->helperText('Deixe vazio para oferecer o brinde em qualquer quantidade de sabores.'),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Quantidade oferecida')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Ativo'),
                        CheckboxList::make('flavor_counts')
                            ->label('Habilitar quando o produto for vendido como')
                            ->options(fn (): array => $this->flavorCountOptions())
                            ->visible(fn (): bool => filled($this->flavorCountOptions()))
                            ->helperText('Deixe vazio para oferecer o brinde em qualquer quantidade de sabores.'),
                    ]),
                DetachAction::make(),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }

    /**
     * Opções de quantidade de sabores da categoria do produto dono (fonte
     * única: Category::resolvedFlavorQuantityOptions). Vazio quando a
     * categoria não permite sabores — o produto é sempre "item simples".
     *
     * @return array<int, string>
     */
    protected function flavorCountOptions(): array
    {
        $product = $this->getOwnerRecord();
        $product->loadMissing(['category.flavorQuantityOptions', 'category.parent.flavorQuantityOptions']);

        return $product->category
            ?->resolvedFlavorQuantityOptions()
            ->mapWithKeys(fn ($option): array => [
                (int) $option->flavor_count => $option->label ?: "{$option->flavor_count} sabor(es)",
            ])
            ->all() ?? [];
    }

    protected function formatFlavorCounts(mixed $state): string
    {
        $counts = is_array($state) ? $state : (json_decode((string) $state, true) ?: []);

        if (empty($counts)) {
            return 'todas as quantidades';
        }

        $labels = $this->flavorCountOptions();

        return collect($counts)
            ->map(fn ($count): string => $labels[(int) $count] ?? "{$count} sabor(es)")
            ->join(', ');
    }
}

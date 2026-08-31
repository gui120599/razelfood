<?php

namespace App\Filament\Tenant\Resources\Categories\RelationManagers;

use App\Models\FlavorQuantityOption;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Quantidades de sabores que o cliente pode escolher para produtos desta
 * categoria (ex.: "Sabor único" = 1, "Meio a meio" = 2). Cada tenant define
 * as suas — não é uma lista fixa (RN-16).
 *
 * Cada opção também define o percentual de estoque/vendagem debitado de
 * CADA sabor no rateio de um combo (ex.: 3 sabores = 33% / 33% / 34%, soma
 * sempre 100%) — ver App\Actions\Orders\Support\CartStockAndPromotionLedger.
 * O último percentual nunca é digitado: é sempre 100% menos a soma dos
 * anteriores, pra garantir que a soma bate exato (sem resíduo de
 * arredondamento) mesmo que o Admin não faça a conta de cabeça.
 */
class FlavorQuantityOptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'flavorQuantityOptions';

    protected static ?string $title = 'Quantidades de sabores';

    /**
     * Teto de sabores suportado pelo form de percentuais — nenhum combo real
     * de pizzaria passa disso; evita renderizar dezenas de campos.
     */
    private const MAX_SHARES_IN_FORM = 12;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // Subcategoria que herda as opções do pai não cadastra as próprias.
        return $ownerRecord->allows_flavors && ! $ownerRecord->inheritsFlavorOptions();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->label('Nome da opção')
                ->placeholder('Ex.: Sabor único, Meio a meio, Três sabores')
                ->required()
                ->maxLength(255),
            TextInput::make('flavor_count')
                ->label('Quantidade de sabores')
                ->helperText('Quantos sabores o cliente escolhe para essa opção (1 = sabor único).')
                ->numeric()
                ->minValue(1)
                ->maxValue(self::MAX_SHARES_IN_FORM)
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set, ?int $state) => $set('flavor_shares', FlavorQuantityOption::equalShares($state ?? 1)))
                ->unique(
                    table: 'flavor_quantity_options',
                    column: 'flavor_count',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('category_id', $this->getOwnerRecord()->id),
                ),
            Grid::make(4)
                ->schema($this->shareFields())
                ->columnSpanFull(),
        ]);
    }

    /**
     * Um TextInput fixo por posição (0 a MAX_SHARES_IN_FORM - 1), visível só
     * até `flavor_count`. O último visível é sempre o rateio, calculado a
     * partir dos outros — nunca editável diretamente.
     *
     * @return array<int, TextInput>
     */
    private function shareFields(): array
    {
        return collect(range(0, self::MAX_SHARES_IN_FORM - 1))
            ->map(function (int $index) {
                $isLast = fn (Get $get): bool => $index === (int) ($get('flavor_count') ?? 1) - 1;

                return TextInput::make("flavor_shares.{$index}")
                    ->label('Sabor '.($index + 1))
                    ->numeric()
                    ->suffix('%')
                    ->step(0.01)
                    ->minValue(0)
                    ->maxValue(100)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => (int) ($get('flavor_count') ?? 0) > $index)
                    ->disabled($isLast)
                    ->dehydrated()
                    ->helperText(fn (Get $get) => $isLast($get) ? 'Calculado automaticamente (resto até 100%).' : null)
                    ->rules([
                        // A checagem só vale pros campos editáveis (todo
                        // índice antes do último) — o próprio último campo
                        // nunca é validado por aqui, ele é sempre o resto.
                        // Decidido em tempo de validação (via $get), não na
                        // montagem do schema, porque "qual é o último" muda
                        // com flavor_count durante a mesma sessão do form.
                        fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get, $index) {
                            $flavorCount = (int) ($get('flavor_count') ?? 1);

                            if ($index >= $flavorCount - 1) {
                                return;
                            }

                            $editableSum = 0.0;

                            for ($i = 0; $i < $flavorCount - 1; $i++) {
                                $editableSum += (float) ($get("flavor_shares.{$i}") ?? 0);
                            }

                            if ($editableSum > 100) {
                                $fail('A soma dos percentuais dos sabores não pode ultrapassar 100%.');
                            }
                        },
                    ])
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $flavorCount = (int) ($get('flavor_count') ?? 1);
                        $lastIndex = $flavorCount - 1;

                        $editableSum = 0.0;

                        for ($i = 0; $i < $lastIndex; $i++) {
                            $editableSum += (float) ($get("flavor_shares.{$i}") ?? 0);
                        }

                        $set("flavor_shares.{$lastIndex}", round(100 - $editableSum, 2));
                    });
            })
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->reorderable('display_order')
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('label')
                    ->label('Nome da opção'),
                TextColumn::make('flavor_count')
                    ->label('Sabores')
                    ->numeric(),
                TextColumn::make('flavor_shares')
                    ->label('Rateio')
                    ->state(fn (FlavorQuantityOption $record) => collect($record->flavor_shares ?? [])
                        ->map(fn ($share) => rtrim(rtrim(number_format((float) $share, 2, ',', '.'), '0'), ',').'%')
                        ->implode(' / ')),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

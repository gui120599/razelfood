<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Overrides pontuais de feature por tenant (RN-41, RF-41). Gerido só pelo
 * painel central — nunca disponível dentro do próprio painel do tenant.
 */
class FeatureOverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'featureOverrides';

    protected static ?string $title = 'Overrides de feature';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('feature_id')
                    ->label('Feature')
                    ->relationship('feature', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', $this->getOwnerRecord()->getKey()),
                    ),
                Toggle::make('enabled')
                    ->label('Habilitada')
                    ->helperText('Ligado: força a feature disponível mesmo fora do plano. Desligado: força a feature indisponível mesmo dentro do plano.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('feature'))
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('feature.name')
                    ->label('Feature'),
                TextColumn::make('feature.key')
                    ->label('Chave')
                    ->fontFamily('mono'),
                IconColumn::make('enabled')
                    ->label('Habilitada')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

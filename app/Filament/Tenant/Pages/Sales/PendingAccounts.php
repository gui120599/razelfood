<?php

namespace App\Filament\Tenant\Pages\Sales;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * "Contas a fechar": pedidos não cancelados que ainda não foram cobrados em
 * nenhuma venda (`orders.sale_id` nulo). Gateada pela feature `pdv` e pela
 * permissão `manage_sales`.
 */
class PendingAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?string $navigationLabel = 'Contas a fechar';

    protected static ?string $title = 'Contas a fechar';

    protected static ?string $slug = 'contas-a-fechar';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::PDV) ?? false)
            && (auth()->user()?->can('manage_sales') ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Tela substituída pela aba Pedidos da Nova Venda (Fase 1.R.2): a URL antiga redireciona.
     * A classe segue até a aprovação para apagá-la.
     */
    public function mount(): void
    {
        $this->redirect(NewSale::getUrl());
    }

    public function table(Table $table): Table
    {
        return $table
            // Tela legada: só pedidos INTEIROS (sem item em venda). Pedido parcial é da Nova Venda.
            ->query(fn (): Builder => Order::query()->awaitingSale()->whereDoesntHave('items', fn (Builder $items) => $items->whereNotNull('sale_id'))->with(['client', 'payments']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->formatStateUsing(fn (Order $record): string => $record->displayNumber())
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->placeholder('Sem cliente')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
                TextColumn::make('origin')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (OrderOrigin $state): string => $state->label()),
                TextColumn::make('declared_payment')
                    ->label('Pagamento declarado')
                    ->state(fn (Order $record): string => $record->payments->pluck('payment_option_name')->unique()->implode(', '))
                    ->placeholder('—'),
                TextColumn::make('grand_total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(OrderStatus::cases())
                        ->reject(fn (OrderStatus $status): bool => $status === OrderStatus::Cancelled)
                        ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])),
                SelectFilter::make('origin')
                    ->label('Origem')
                    ->options(collect(OrderOrigin::cases())->mapWithKeys(
                        fn (OrderOrigin $origin): array => [$origin->value => $origin->label()]
                    )),
            ])
            ->headerActions([
                Action::make('standaloneSale')
                    ->label('Venda avulsa')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('gray')
                    ->url(fn (): string => CloseAccount::getUrl()),
            ])
            ->recordActions([
                Action::make('closeAccount')
                    ->label('Fechar conta')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('primary')
                    ->url(fn (Order $record): string => CloseAccount::getUrl(['orders' => $record->id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('closeSelected')
                        ->label('Fechar conta dos selecionados')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->action(function (Collection $records, PendingAccounts $livewire): void {
                            $ids = $records->modelKeys();
                            sort($ids);

                            $livewire->redirect(CloseAccount::getUrl(['orders' => implode(',', $ids)]));
                        }),
                ]),
            ])
            ->emptyStateHeading('Nenhuma conta a fechar')
            ->emptyStateDescription('Pedidos sem venda aparecem aqui. Para vender sem pedido, use "Venda avulsa".');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }
}

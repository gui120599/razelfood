<?php

namespace App\Filament\Tenant\Resources\Orders\Tables;

use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Filament\Tenant\Pages\Orders\AttendOrder;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->placeholder('Sem cliente')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),
                TextColumn::make('origin')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (OrderOrigin $state) => $state->label()),
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
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        fn (OrderStatus $status): array => [$status->value => $status->label()]
                    )),
                SelectFilter::make('origin')
                    ->label('Origem')
                    ->options(collect(OrderOrigin::cases())->mapWithKeys(
                        fn (OrderOrigin $origin): array => [$origin->value => $origin->label()]
                    )),
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->headerActions([
                Action::make('newOrder')
                    ->label('Novo Pedido')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn () => AttendOrder::canAccess())
                    ->url(fn () => AttendOrder::getUrl()),
            ])
            ->recordActions([
                Action::make('attend')
                    ->label('Editar')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->visible(fn (Order $record) => AttendOrder::canAccess()
                        && $record->status->isEditableContentWise()
                        && (! $record->status->requiresAdvancedPermissionToEdit() || (auth()->user()?->can('edit_order_advanced_status') ?? false)))
                    ->url(fn (Order $record) => AttendOrder::getUrl(['order' => $record])),
                ViewAction::make(),
            ]);
    }
}

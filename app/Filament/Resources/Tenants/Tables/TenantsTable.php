<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Enums\CentralRole;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalEnvironment;
use App\Enums\TenantStatus;
use App\Filament\Support\InputMasks;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('plan')
                // Notas AUTORIZADAS e ainda válidas (emitidas ou com cancelamento pendente) em produção (homologação
                // não é cobrada), mês corrente e total. Documentos apagados (soft delete) ficam de fora.
                ->withCount([
                    'fiscalDocuments as nfce_month_count' => fn (Builder $documents) => $documents
                        ->withoutGlobalScope(TenantScope::class)
                        ->whereIn('status', FiscalDocumentStatus::standingValues())
                        ->where('environment', FiscalEnvironment::Production->value)
                        ->where('authorized_at', '>=', now()->startOfMonth()),
                    'fiscalDocuments as nfce_total_count' => fn (Builder $documents) => $documents
                        ->withoutGlobalScope(TenantScope::class)
                        ->whereIn('status', FiscalDocumentStatus::standingValues())
                        ->where('environment', FiscalEnvironment::Production->value),
                ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TenantStatus $state) => $state->label())
                    ->color(fn (TenantStatus $state) => match ($state) {
                        TenantStatus::Active => 'success',
                        TenantStatus::Suspended => 'warning',
                        TenantStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('plan.name')
                    ->label('Plano')
                    ->badge(),
                TextColumn::make('whatsapp_number')
                    ->label('WhatsApp')
                    ->formatStateUsing(fn (?string $state): ?string => InputMasks::formatPhone($state)),
                TextColumn::make('nfce_month_count')
                    ->label('NFC-e (mês)')
                    ->sortable()
                    ->tooltip('NFC-e autorizadas em produção neste mês'),
                TextColumn::make('nfce_total_count')
                    ->label('NFC-e (total)')
                    ->sortable()
                    ->toggleable()
                    ->tooltip('NFC-e autorizadas em produção, desde sempre'),
                TextColumn::make('users_count')
                    ->label('Usuários')
                    ->counts('users'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(
                        fn (TenantStatus $status) => [$status->value => $status->label()]
                    )),
                SelectFilter::make('plan_id')
                    ->label('Plano')
                    ->relationship('plan', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('accessPanel')
                    ->label('Acessar painel')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    // Só o super admin Plataforma tem canAccessTenant() para
                    // qualquer tenant (ver App\Models\User + .ai/rules/models-filament.md);
                    // Support levaria 403 na URL de destino.
                    ->visible(fn (): bool => Auth::user()?->hasCentralRole(CentralRole::Platform) ?? false)
                    ->url(fn (Tenant $record): string => Filament::getPanel('tenant')->getUrl($record), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}

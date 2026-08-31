<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Rules\ValidTenantSlug;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeSlug')
                ->label('Alterar slug')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Alterar slug do tenant')
                ->modalDescription('Alterar o slug muda o endereço do cardápio publicado (razelfood.com.br/{slug}). QR codes, cardápio impresso e links de WhatsApp já divulgados param de funcionar até serem atualizados. Confirme que o cliente foi avisado do impacto antes de continuar.')
                ->schema(fn (): array => [
                    TextInput::make('slug')
                        ->label('Novo slug')
                        ->required()
                        ->rules([
                            new ValidTenantSlug,
                            Rule::unique('tenants', 'slug')->ignore($this->record->id),
                        ]),
                ])
                ->fillForm(fn (Tenant $record): array => ['slug' => $record->slug])
                ->action(function (Tenant $record, array $data): void {
                    $oldSlug = $record->slug;
                    $record->update(['slug' => $data['slug']]);

                    // ResolveTenantFromPath cacheia o tenant por slug
                    // (tenant:slug:{slug}). Sem invalidar as duas chaves, o
                    // slug antigo continuaria servindo o tenant e o novo daria
                    // 404 até o TTL expirar.
                    Cache::forget("tenant:slug:{$oldSlug}");
                    Cache::forget("tenant:slug:{$record->slug}");

                    $this->fillForm();
                }),
            DeleteAction::make(),
        ];
    }
}

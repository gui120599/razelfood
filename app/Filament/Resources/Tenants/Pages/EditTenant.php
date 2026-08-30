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
                ->modalDescription('Alterar o slug muda o subdomínio do cardápio publicado. QR codes, cardápio impresso e links de WhatsApp já divulgados param de funcionar até serem atualizados. Confirme que o cliente foi avisado do impacto antes de continuar.')
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
                    $record->update(['slug' => $data['slug']]);
                    $this->fillForm();
                }),
            DeleteAction::make(),
        ];
    }
}

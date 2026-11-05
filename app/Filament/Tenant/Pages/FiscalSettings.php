<?php

namespace App\Filament\Tenant\Pages;

use App\Enums\FiscalEnvironment;
use App\Enums\TaxRegime;
use App\Filament\Support\FiscalSettingsFields;
use App\Models\TenantFiscalSettings;
use App\Services\Address\IbgeService;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Fiscal\FiscalReadiness;
use App\Support\Fiscal\FiscalReadinessReport;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Throwable;
use UnitEnum;

/**
 * Dados fiscais do estabelecimento para a NFC-e (NFE.io): identificação do
 * emitente, credenciais e o checklist de prontidão. Gateada pela feature
 * `nfe_emissao`. A emissão só pode ser habilitada com o checklist completo.
 */
class FiscalSettings extends Page
{
    // Alias em vez de sobrescrever direto: combina a checagem de feature
    // (RN-43) com a permissão do Shield — ver .ai/rules/pages.md.
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Dados fiscais';

    protected static ?string $title = 'Dados fiscais';

    protected static ?string $slug = 'dados-fiscais';

    protected static ?int $navigationSort = -5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false)
            && static::pageShieldCanAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false)
            && static::pageShieldShouldRegisterNavigation();
    }

    public function mount(): void
    {
        $tenant = CurrentTenant::get();
        $settings = $tenant->fiscalSettings;

        // Sem linha fiscal ainda: o Filament não aplica o default dos campos quando o
        // fill() já traz outras chaves, então os valores iniciais vão explícitos.
        $state = $settings?->only(FiscalSettingsFields::names()) ?? [
            'tax_regime' => TaxRegime::SimplesNacional->value,
            'environment' => FiscalEnvironment::Test->value,
            'nfce_series' => 1,
        ];
        $state['emission_enabled'] = $settings?->isEnabled() ?? false;

        if ($settings === null || blank($settings->city_ibge_code)) {
            $state = [...$state, ...$this->cityFromAddress()];
        }

        $this->form->fill($state);
    }

    #[Computed]
    public function readiness(): FiscalReadinessReport
    {
        return app(FiscalReadiness::class)->forTenant(CurrentTenant::get()); // recarrega o tenant internamente
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Prontidão para emitir NFC-e')
                    ->description('O que ainda falta no cadastro. Atualiza ao salvar.')
                    ->schema([
                        Text::make(fn (): HtmlString => new HtmlString(
                            view('filament.tenant.pages.fiscal-readiness', ['report' => $this->readiness])->render()
                        )),
                    ]),
                ...FiscalSettingsFields::sections(),
                Section::make('Emissão')
                    ->schema([
                        Toggle::make('emission_enabled')
                            ->label('Habilitar a emissão de NFC-e')
                            ->helperText('Só liga com o checklist de prontidão completo. Desligar interrompe novas emissões.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $wantsEnabled = (bool) ($state['emission_enabled'] ?? false);
        unset($state['emission_enabled']);

        $tenant = CurrentTenant::get();
        $settings = TenantFiscalSettings::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $settings->fill($state)->save();

        $report = app(FiscalReadiness::class)->forTenant($tenant);
        $enabled = $wantsEnabled && $report->ready();

        $settings->forceFill(['enabled_at' => $enabled ? ($settings->enabled_at ?? now()) : null]);

        if ($settings->isDirty('enabled_at')) {
            $settings->save();
        }

        $tenant->unsetRelation('fiscalSettings');
        $this->data['emission_enabled'] = $enabled;
        // O segredo digitado não pode ficar no estado do componente (voltaria ao navegador a cada roundtrip).
        $this->data['nfeio_api_key'] = null;
        $this->data['nfeio_webhook_secret'] = null;

        if ($wantsEnabled && ! $enabled) {
            Notification::make()
                ->title('Dados salvos, mas a emissão não foi habilitada')
                ->body('Complete o checklist de prontidão acima e tente de novo.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Dados fiscais salvos')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar')
                ->submit('save'),
        ];
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment(Alignment::Start)
                    ->key('form-actions'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    /**
     * Sugere UF e cidade (código IBGE) a partir do endereço do estabelecimento.
     * Auxiliar: se o IBGE estiver fora do ar ou o nome não casar, devolve vazio.
     *
     * @return array<string, mixed>
     */
    private function cityFromAddress(): array
    {
        $tenant = CurrentTenant::get();

        if (blank($tenant->state) || blank($tenant->city)) {
            return [];
        }

        try {
            $city = collect(app(IbgeService::class)->citiesOf($tenant->state))
                ->first(fn (array $city): bool => mb_strtolower($city['name']) === mb_strtolower($tenant->city));
        } catch (Throwable) {
            return [];
        }

        return $city === null ? [] : ['state_uf' => $tenant->state, 'city_ibge_code' => $city['ibge_code']];
    }
}

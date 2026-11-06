<?php

namespace App\Filament\Support;

use App\Enums\FiscalEnvironment;
use App\Enums\TaxRegime;
use App\Models\TenantFiscalSettings;
use App\Rules\ValidStateRegistration;
use App\Services\Address\IbgeService;
use App\Support\CurrentTenant;
use App\Support\Fiscal\StateRegistration;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Livewire\Component;

/**
 * Seções "Identificação fiscal" e "Integração NFE.io", compartilhadas entre o
 * painel central (TenantForm, via relação `fiscalSettings`) e a página
 * "Dados fiscais" do painel do tenant — mesmo rótulo/máscara/validação nos
 * dois lugares. CNPJ e endereço NÃO ficam aqui: continuam em
 * EstablishmentDocumentFields (fonte única em `tenants`).
 *
 * Segredos (chave de API, segredo do webhook): nunca voltam ao navegador. O
 * campo hidrata vazio, não revela e só é gravado quando preenchido — em branco
 * mantém o valor atual.
 */
final class FiscalSettingsFields
{
    /**
     * Campos NÃO secretos, para o `mount()` da página do tenant popular o form.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['legal_name', 'state_registration', 'tax_regime', 'state_uf', 'city_ibge_code', 'nfce_series', 'approximate_tax_percent', 'environment', 'nfeio_company_id'];
    }

    /**
     * @return list<Section>
     */
    public static function sections(bool $viaRelationship = false): array
    {
        // Na edição central o tenant pode ainda não ter a linha fiscal: os campos com
        // default no banco (regime, ambiente, série) não são `required` e um `null`
        // nunca é gravado por cima do default (ver withoutNullDefaults).
        $sections = [self::identification(required: ! $viaRelationship), self::integration(required: ! $viaRelationship)];

        if ($viaRelationship) {
            foreach ($sections as $section) {
                $section
                    ->relationship('fiscalSettings')
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::withoutNullDefaults($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::withoutNullDefaults($data));
            }
        }

        return $sections;
    }

    /**
     * Remove `null`/vazio dos campos que têm default no banco (`tax_regime`,
     * `environment`, `nfce_series`), para o default valer na criação e o valor
     * atual ser mantido na edição.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutNullDefaults(array $data): array
    {
        foreach (['tax_regime', 'environment', 'nfce_series'] as $field) {
            if (array_key_exists($field, $data) && blank($data[$field])) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    private static function identification(bool $required): Section
    {
        return Section::make('Identificação fiscal')
            ->description('Dados do emitente da NFC-e. O CNPJ e o endereço vêm de "Estabelecimento".')
            ->columns(6)
            ->schema([
                TextInput::make('legal_name')
                    ->label('Razão social')
                    ->maxLength(255)
                    ->columnSpan(4),
                TextInput::make('state_registration')
                    ->label('Inscrição Estadual')
                    ->rule(new ValidStateRegistration)
                    ->dehydrateStateUsing(fn (?string $state): ?string => StateRegistration::normalize($state))
                    ->maxLength(20)
                    ->helperText('Só números, ou ISENTO.')
                    ->columnSpan(2),
                Select::make('tax_regime')
                    ->label('Regime tributário')
                    ->options(collect(TaxRegime::cases())->mapWithKeys(fn (TaxRegime $regime): array => [
                        $regime->value => $regime->label().($regime->isSupported() ? '' : ' (não suportado nesta versão)'),
                    ])->all())
                    ->default(TaxRegime::SimplesNacional->value)
                    ->disableOptionWhen(fn (string $value): bool => ! (TaxRegime::tryFrom($value)?->isSupported() ?? false))
                    ->required($required)
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (TaxRegime::tryFrom((string) $value)?->isSupported() === false) {
                            $fail(TaxRegime::unsupportedMessage());
                        }
                    }])
                    ->columnSpan(3),
                Select::make('state_uf')
                    ->label('UF')
                    ->options(fn (): array => collect(app(IbgeService::class)->states())
                        ->mapWithKeys(fn (array $state): array => [$state['uf'] => "{$state['name']} ({$state['uf']})"])
                        ->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('city_ibge_code', null))
                    ->columnSpan(1),
                Select::make('city_ibge_code')
                    ->label('Cidade (código IBGE)')
                    ->options(fn (Get $get): array => filled($get('state_uf'))
                        ? collect(app(IbgeService::class)->citiesOf($get('state_uf')))
                            ->mapWithKeys(fn (array $city): array => [$city['ibge_code'] => $city['name']])
                            ->all()
                        : [])
                    ->searchable()
                    ->dehydrated()
                    ->disabled(fn (Get $get): bool => blank($get('state_uf')))
                    ->helperText('Selecione a UF primeiro.')
                    ->columnSpan(2),
                TextInput::make('nfce_series')
                    ->label('Série da NFC-e')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(999)
                    ->default(1)
                    ->required($required)
                    ->columnSpan(3),
                TextInput::make('approximate_tax_percent')
                    ->label('Imposto aproximado (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->rule('decimal:0,2')
                    ->suffix('%')
                    ->helperText('Percentual aproximado de tributos (Lei da Transparência), usado em relatórios.')
                    ->columnSpan(3),
            ]);
    }

    private static function integration(bool $required): Section
    {
        return Section::make('Integração com a NFE.io')
            ->description('Credenciais da empresa na NFE.io. Ficam criptografadas e a chave salva nunca é exibida de novo: preencha só para cadastrar ou trocar.')
            ->columns(2)
            ->schema([
                Select::make('environment')
                    ->label('Ambiente')
                    ->options(collect(FiscalEnvironment::cases())->mapWithKeys(fn (FiscalEnvironment $environment): array => [$environment->value => $environment->label()])->all())
                    ->default(FiscalEnvironment::Test->value)
                    ->required($required)
                    ->helperText('Comece sempre em homologação; só passe para produção depois de uma nota de teste autorizada.'),
                TextInput::make('nfeio_company_id')
                    ->label('ID da empresa (companyId)')
                    ->trim()
                    ->maxLength(100)
                    ->rules([fn (Component $livewire): Closure => self::uniqueCompanyIdRule($livewire)]),
                self::secret('nfeio_api_key', 'Chave de API'),
                self::secret('nfeio_webhook_secret', 'Segredo do webhook'),
            ]);
    }

    /**
     * O mesmo companyId não pode estar em dois estabelecimentos: a emissão (e o
     * webhook) acabariam resolvendo a empresa — e o tenant — errados. Consulta
     * sem o escopo de tenant e ignora o próprio tenant (página do tenant: o atual;
     * painel central: o tenant em edição).
     */
    private static function uniqueCompanyIdRule(Component $livewire): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($livewire): void {
            if (blank($value)) {
                return;
            }

            $ownTenantId = CurrentTenant::id() ?? (method_exists($livewire, 'getRecord') ? $livewire->getRecord()?->getKey() : null);

            $taken = TenantFiscalSettings::query()
                ->withoutGlobalScopes()
                ->where('nfeio_company_id', $value)
                ->when($ownTenantId !== null, fn ($query) => $query->where('tenant_id', '!=', $ownTenantId))
                ->exists();

            if ($taken) {
                $fail('Este ID de empresa já está cadastrado em outro estabelecimento.');
            }
        };
    }

    private static function secret(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->password()
            ->trim()
            ->revealable(false)
            ->autocomplete('new-password')
            ->maxLength(255)
            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->helperText('Em branco = manter o valor já salvo.');
    }
}

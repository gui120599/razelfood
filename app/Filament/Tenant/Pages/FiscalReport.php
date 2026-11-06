<?php

namespace App\Filament\Tenant\Pages;

use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use App\Support\Fiscal\FiscalReportQuery;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Relatório para o contador (equivalente ao "Relatório fiscal (NFC-e)" do Pizzaria-App): NFC-e
 * autorizadas no período com os itens agrupados por NCM, categoria, produto, CFOP/CSOSN, forma de
 * pagamento ou dia, mais a lista de números reservados e não usados. A regra fica em
 * FiscalReportQuery (a mesma do CSV e da impressão). Gateada pela feature `nfe_emissao`.
 */
class FiscalReport extends Page
{
    // Alias em vez de sobrescrever direto: combina o gate de feature (RN-43) com a permissão do
    // Shield — ver .ai/rules/pages.md.
    use HasPageShield {
        canAccess as pageShieldCanAccess;
        shouldRegisterNavigation as pageShieldShouldRegisterNavigation;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?string $navigationLabel = 'Relatório fiscal (NFC-e)';

    protected static ?string $title = 'Relatório fiscal (NFC-e)';

    protected static ?string $slug = 'relatorio-fiscal';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.tenant.pages.fiscal-report';

    public string $startDate = '';

    public string $endDate = '';

    /** @var array<int, string> */
    public array $groupings = ['ncm', 'category'];

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
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->toDateString();
    }

    #[Computed]
    public function report(): FiscalReportQuery
    {
        return FiscalReportQuery::forPeriod((int) CurrentTenant::id(), $this->startDate, $this->endDate);
    }

    /**
     * Mesmos parâmetros para o CSV e a impressão.
     *
     * @return array<string, mixed>
     */
    public function exportParameters(): array
    {
        return [
            'start' => $this->report->start()->toDateString(),
            'end' => $this->report->end()->toDateString(),
            'groupings' => FiscalReportQuery::dimensionsFrom($this->groupings),
        ];
    }
}

<?php

namespace App\Filament\Tenant\Pages\Concerns;

use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Filtro de período compartilhado entre as dashboards de relatório
 * (Reports, Deliveries). Usa o `HasFiltersForm` do Filament — a página
 * precisa dar `use HasFiltersForm` também. Os valores ficam em
 * `$this->filters['startDate'|'endDate']`.
 */
trait HasReportPeriodFilter
{
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('preset')
                ->label('Período')
                ->options(ReportPeriod::options())
                ->default(ReportPeriod::Last30Days->value)
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    $range = ReportPeriod::tryFrom($state ?? '')?->range();

                    if ($range !== null) {
                        $set('startDate', $range[0]->toDateString());
                        $set('endDate', $range[1]->toDateString());
                    }
                }),

            DatePicker::make('startDate')
                ->label('De')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(ReportPeriod::Last30Days->range()[0]->toDateString())
                ->maxDate(fn (Get $get) => $get('endDate') ?: now())
                ->afterStateUpdated(fn (Set $set) => $set('preset', ReportPeriod::Custom->value)),

            DatePicker::make('endDate')
                ->label('Até')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(now()->toDateString())
                ->maxDate(now())
                ->afterStateUpdated(fn (Set $set) => $set('preset', ReportPeriod::Custom->value)),
        ])->columns(3);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function resolvedPeriod(): array
    {
        return ReportPeriod::resolveRange(
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null,
        );
    }
}

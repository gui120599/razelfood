<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;

/**
 * Presets de período do dashboard de relatórios (RF-31) + resolução do
 * intervalo efetivo. `resolveRange()` é a única fonte de verdade do
 * intervalo — usada pelos widgets (a partir de `$this->pageFilters`) e pela
 * exportação CSV, sempre com fallback pros últimos 30 dias.
 */
enum ReportPeriod: string
{
    case Today = 'today';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case ThisMonth = 'this_month';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Hoje',
            self::Last7Days => 'Últimos 7 dias',
            self::Last30Days => 'Últimos 30 dias',
            self::ThisMonth => 'Mês atual',
            self::Custom => 'Personalizado',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function range(): ?array
    {
        $today = CarbonImmutable::today();

        return match ($this) {
            self::Today => [$today, $today],
            self::Last7Days => [$today->subDays(6), $today],
            self::Last30Days => [$today->subDays(29), $today],
            self::ThisMonth => [$today->startOfMonth(), $today],
            self::Custom => null,
        };
    }

    /**
     * Intervalo efetivo a partir dos valores crus do filtro de página
     * (podem vir nulos, fora de ordem ou não-parseáveis).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function resolveRange(?string $start, ?string $end): array
    {
        $default = self::Last30Days->range();

        $startDate = self::parse($start) ?? $default[0];
        $endDate = self::parse($end) ?? $default[1];

        if ($startDate->greaterThan($endDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [$startDate->startOfDay(), $endDate->startOfDay()];
    }

    private static function parse(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

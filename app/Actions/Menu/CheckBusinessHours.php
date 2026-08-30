<?php

namespace App\Actions\Menu;

use App\Models\BusinessHour;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * RN-23: sistema não aceita pedido fora do horário de funcionamento.
 * Trata turnos que cruzam a meia-noite (ex.: 22h-02h), mesma técnica de
 * FlashPromotion::isWithinRecurringWindow().
 */
class CheckBusinessHours
{
    private const WEEKDAY_LABELS = [
        0 => 'domingo', 1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira',
        4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado',
    ];

    public function __invoke(?Carbon $now = null): BusinessHoursStatus
    {
        $now = $now ?? now();
        $hours = BusinessHour::where('is_active', true)->get();

        if ($this->isOpenAt($hours, $now)) {
            return new BusinessHoursStatus(isOpen: true);
        }

        return new BusinessHoursStatus(isOpen: false, message: $this->findNextOpeningMessage($hours, $now));
    }

    private function isOpenAt(Collection $hours, Carbon $now): bool
    {
        $currentTime = $now->format('H:i:s');
        $weekday = $now->dayOfWeek;
        $previousWeekday = ($weekday + 6) % 7;

        foreach ($hours as $hour) {
            $start = $hour->opens_at->format('H:i:s');
            $end = $hour->closes_at->format('H:i:s');

            if ($start <= $end) {
                if ($hour->weekday === $weekday && $currentTime >= $start && $currentTime < $end) {
                    return true;
                }

                continue;
            }

            // Turno cruza a meia-noite (ex.: 22h-02h).
            if ($hour->weekday === $weekday && $currentTime >= $start) {
                return true;
            }

            if ($hour->weekday === $previousWeekday && $currentTime < $end) {
                return true;
            }
        }

        return false;
    }

    private function findNextOpeningMessage(Collection $hours, Carbon $now): ?string
    {
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->copy()->addDays($offset);
            $weekday = $day->dayOfWeek;

            $shiftsToday = $hours
                ->filter(fn (BusinessHour $hour) => $hour->weekday === $weekday)
                ->sortBy(fn (BusinessHour $hour) => $hour->opens_at->format('H:i:s'));

            foreach ($shiftsToday as $shift) {
                $opensAt = $day->copy()->setTimeFrom($shift->opens_at);

                if ($offset === 0 && $opensAt->lessThanOrEqualTo($now)) {
                    continue;
                }

                $label = match ($offset) {
                    0 => 'hoje',
                    1 => 'amanhã',
                    default => self::WEEKDAY_LABELS[$weekday],
                };

                return "Voltamos {$label} às {$opensAt->format('H:i')}";
            }
        }

        return null;
    }
}

<?php

namespace App\Support\Orders;

/**
 * Formata minutos decorridos para exibição no card da Central de Pedidos
 * (seção 8 da spec): "02 min", "15 min", "1h 12min".
 */
class DurationFormatter
{
    public static function minutes(int $totalMinutes): string
    {
        $totalMinutes = max(0, $totalMinutes);

        if ($totalMinutes < 60) {
            return sprintf('%02d min', $totalMinutes);
        }

        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        return $minutes > 0
            ? sprintf('%dh %02dmin', $hours, $minutes)
            : sprintf('%dh', $hours);
    }
}

<?php

namespace App\Support;

/**
 * Gera as variáveis CSS de cor do cardápio público a partir da
 * `tenants.primary_color` de cada tenant (docs/identidade-visual-design-system.md,
 * seção 5.1). O cardápio público segue a marca do restaurante, não a do
 * RazelFood — só cai no fallback abaixo enquanto o tenant não configurou
 * `primary_color` no onboarding (estado transitório, não identidade fixa).
 */
final class TenantColorScale
{
    private const DEFAULT_HEX = '#FA6400';

    /**
     * Emite `--tenant-primary` (preserva o hex de entrada, consumido hoje em
     * vários pontos do cardápio) e `--tenant-50`..`--tenant-900` (variações
     * de luminosidade em HSL a partir da mesma cor).
     */
    public static function cssVariables(?string $hex): string
    {
        $hex = self::sanitize($hex) ?? self::DEFAULT_HEX;

        [$h, $s, $l] = self::hexToHsl($hex);

        $stops = [
            50 => min($l + 45, 96), 100 => min($l + 35, 92), 200 => min($l + 25, 88),
            300 => min($l + 15, 82), 400 => min($l + 7, 75), 500 => $l,
            600 => max($l - 8, 15), 700 => max($l - 16, 10), 800 => max($l - 24, 7), 900 => max($l - 32, 4),
        ];

        $css = ":root{--tenant-primary:{$hex};";
        foreach ($stops as $step => $lightness) {
            $css .= "--tenant-{$step}:hsl({$h} {$s}% {$lightness}%);";
        }

        return $css.'}';
    }

    /**
     * Só aceita #RRGGBB estrito — esse valor é interpolado em Blade raw
     * ({!! !!}, sem escape), então um hex malformado não pode carregar `;`/`}`
     * capazes de quebrar a custom property e injetar CSS arbitrário.
     */
    private static function sanitize(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [h (0-360), s (0-100), l (0-100)]
     */
    private static function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(
            fn (string $channel): float => hexdec($channel) / 255,
            str_split(ltrim($hex, '#'), 2),
        );

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0, 0, (int) round($l * 100)];
        }

        $delta = $max - $min;
        $s = $l > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

        $h = match ($max) {
            $r => fmod((($g - $b) / $delta) + ($g < $b ? 6 : 0), 6),
            $g => (($b - $r) / $delta) + 2,
            default => (($r - $g) / $delta) + 4,
        } * 60;

        return [(int) round($h), (int) round($s * 100), (int) round($l * 100)];
    }
}

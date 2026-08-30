<?php

namespace App\Support;

/**
 * Fonte única em PHP dos tokens de cor de marca RazelFood.
 * Espelha a seção 2.2 de docs/identidade-visual-design-system.md — manter em
 * sincronia manual com resources/css/filament/brand.css (Tailwind CSS-first
 * não é importável em PHP sem um passo de build extra).
 */
final class BrandColors
{
    public const ORANGE_600 = '#FA6400';

    public const TEAL_500 = '#007896';

    public const TEAL_300 = '#0096B4';

    public const AMBER_300 = '#FAB400';

    public const NAVY_900 = '#001E3C';

    public const SUCCESS = '#16A34A';

    public const DANGER = '#DC2626';

    public const WARNING = '#F59E0B';
}

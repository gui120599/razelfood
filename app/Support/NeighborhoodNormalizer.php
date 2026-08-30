<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normaliza bairro/cidade antes de gravar em `delivery_zone_neighborhoods`
 * e antes de comparar no checkout (App\Actions\Orders\ResolveDeliveryFee) —
 * evita falso "bairro não configurado" por divergência de maiúsculas ou
 * acentuação entre o que o Admin cadastrou e o que o ViaCEP retorna.
 */
class NeighborhoodNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::of($value)->squish()->ascii()->lower()->toString();

        return $normalized === '' ? null : $normalized;
    }
}

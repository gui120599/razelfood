<?php

namespace App\Support\Fiscal;

/**
 * Normaliza a Inscrição Estadual para gravação: "ISENTO" em maiúsculas ou só
 * os dígitos (sem pontos, traços, barras ou espaços). Vazio vira null.
 */
final class StateRegistration
{
    public static function normalize(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[.\-\/\s]/', '', $value));

        return $clean === '' ? null : $clean;
    }
}

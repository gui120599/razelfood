<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RN-04: slug em minúsculas, sem espaços/acentos (só letras/números/hífen),
 * e fora da lista de palavras reservadas (config/tenancy.php). Unicidade em
 * si fica a cargo do ->unique() nativo do Filament no campo.
 */
class ValidTenantSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value)) {
            $fail('O slug deve usar apenas letras minúsculas, números e hífen (sem espaços ou acentos).');

            return;
        }

        if (in_array($value, config('tenancy.reserved_slugs'), true)) {
            $fail('Este slug é uma palavra reservada do sistema e não pode ser usado.');
        }
    }
}

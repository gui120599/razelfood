<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Inscrição Estadual: "ISENTO" ou de 2 a 14 dígitos (o tamanho varia por UF;
 * o dígito verificador por estado não é conferido). Opcional: vazio passa.
 */
class ValidStateRegistration implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $value = trim((string) $value);

        if (strcasecmp($value, 'ISENTO') === 0) {
            return;
        }

        if (! preg_match('/^\d{2,14}$/', preg_replace('/[.\-\/\s]/', '', $value))) {
            $fail('Inscrição Estadual inválida: informe só números (2 a 14 dígitos) ou ISENTO.');
        }
    }
}

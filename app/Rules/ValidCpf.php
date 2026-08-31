<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida um CPF pelos dois dígitos verificadores (módulo 11). Aceita o
 * valor com ou sem máscara — só os 11 dígitos importam. Campo opcional:
 * valor vazio passa (a obrigatoriedade fica a cargo do ->required() do form).
 */
class ValidCpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            $fail('CPF inválido.');

            return;
        }

        if (! $this->hasValidCheckDigits($digits)) {
            $fail('CPF inválido.');
        }
    }

    private function hasValidCheckDigits(string $digits): bool
    {
        foreach ([9, 10] as $length) {
            $weight = $length + 1;
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * $weight;
                $weight--;
            }

            $remainder = $sum % 11;
            $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

            if ($checkDigit !== (int) $digits[$length]) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida um CNPJ pelos dois dígitos verificadores (módulo 11). Aceita o
 * valor com ou sem máscara — só os 14 dígitos importam. Campo opcional:
 * valor vazio passa (a obrigatoriedade fica a cargo do ->required() do form).
 */
class ValidCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            $fail('CNPJ inválido.');

            return;
        }

        if (! $this->hasValidCheckDigits($digits)) {
            $fail('CNPJ inválido.');
        }
    }

    private function hasValidCheckDigits(string $digits): bool
    {
        foreach ([12, 13] as $length) {
            $weight = $length - 7;
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
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

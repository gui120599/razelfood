<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * GTIN/EAN (8, 12, 13 ou 14 dígitos) com o dígito verificador GS1 (módulo 10).
 * Opcional: vazio passa.
 */
class ValidGtin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        // Ignora só espaços, pontos e traços de formatação; letras ou outros símbolos invalidam.
        $digits = preg_replace('/[\s.\-]/', '', (string) $value);

        if (! ctype_digit($digits) || ! in_array(strlen($digits), [8, 12, 13, 14], true) || ! $this->hasValidCheckDigit($digits)) {
            $fail('GTIN inválido: confira o código de barras (8, 12, 13 ou 14 dígitos).');
        }
    }

    private function hasValidCheckDigit(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $sum = 0;

        // Da direita para a esquerda do corpo: pesos 3, 1, 3, 1...
        foreach (array_reverse(str_split($body)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === (int) substr($digits, -1);
    }
}

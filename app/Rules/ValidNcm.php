<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * NCM: 8 dígitos (aceita a máscara 0000.00.00). Campo opcional: vazio passa
 * (a obrigatoriedade fica a cargo do ->required() do form). Valida só o
 * formato — a tabela oficial de NCMs não é consultada.
 */
class ValidNcm implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        // Aceita só o ponto da máscara (0000.00.00); qualquer outro caractere é inválido.
        if (! preg_match('/^\d{8}$/', str_replace('.', '', (string) $value))) {
            $fail('NCM inválido: informe os 8 dígitos.');
        }
    }
}

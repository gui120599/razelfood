<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\RawJs;

/**
 * Máscaras compartilhadas entre os painéis central e do tenant.
 * Centralizadas aqui porque são aplicadas de forma idêntica em vários
 * Resources — mudar a regra de normalização em um único lugar evita
 * duplicar (e divergir) a mesma lógica em cada form.
 *
 * Dois grupos:
 * - `phone()/cep()/cnpj()/money()` — transformers `(TextInput): TextInput`
 *   que aplicam a máscara de digitação e normalizam o valor gravado no banco
 *   (só dígitos; `money()` grava decimal puro).
 * - `formatPhone()/formatCep()/formatCnpj()` — formatadores de EXIBIÇÃO puros
 *   `(?string): ?string` para reconstruir a máscara a partir dos dígitos do
 *   banco em `TextColumn`/`TextEntry` via `->formatStateUsing()`. Valor que
 *   não bate a contagem de dígitos esperada é devolvido intacto.
 */
final class InputMasks
{
    /**
     * Telefone BR: alterna entre fixo (10 dígitos) e celular (11 dígitos)
     * conforme a quantidade de dígitos já digitados.
     *
     * Transformer (como money(), acima): aplica a máscara e o ->stripCharacters()
     * que remove os caracteres do formato ANTES da validação e do save — o
     * banco guarda só os dígitos. Seguro fazer isso indiscriminadamente aqui
     * (diferente de money()): num telefone/CEP/CNPJ nenhum de '(', ')', ' ',
     * '.', '/' ou '-' é significativo, então strip nunca corrompe um valor já
     * em formato puro.
     */
    public static function phone(TextInput $input): TextInput
    {
        return $input
            ->tel()
            ->mask(RawJs::make(<<<'JS'
                $input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'
            JS))
            ->stripCharacters(['(', ')', ' ', '-']);
    }

    public static function cep(TextInput $input): TextInput
    {
        return $input
            ->mask('99999-999')
            ->stripCharacters('-');
    }

    public static function cnpj(TextInput $input): TextInput
    {
        return $input
            ->mask('99.999.999/9999-99')
            ->stripCharacters(['.', '/', '-']);
    }

    /**
     * Reconstrói a máscara de telefone BR a partir dos dígitos gravados.
     * Aceita 10/11 dígitos (fixo/celular) e 12/13 dígitos (com o código do
     * país, ex.: 55 no `whatsapp_number`). Fora disso devolve o valor cru.
     */
    public static function formatPhone(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return match (strlen($digits)) {
            10 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6)),
            11 => sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7)),
            12 => sprintf('+%s (%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 2), substr($digits, 4, 4), substr($digits, 8)),
            13 => sprintf('+%s (%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 2), substr($digits, 4, 5), substr($digits, 9)),
            default => $value,
        };
    }

    /**
     * Reconstrói a máscara de CEP (99999-999) a partir dos 8 dígitos gravados.
     */
    public static function formatCep(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) === 8
            ? substr($digits, 0, 5).'-'.substr($digits, 5)
            : $value;
    }

    /**
     * Reconstrói a máscara de CNPJ (99.999.999/9999-99) a partir dos 14
     * dígitos gravados.
     */
    public static function formatCnpj(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) === 14
            ? sprintf(
                '%s.%s.%s/%s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 4),
                substr($digits, 12),
            )
            : $value;
    }

    /**
     * Aplica a máscara monetária BR (milhar com ponto, decimal com vírgula)
     * a um TextInput monetário existente, preservando os métodos já
     * encadeados nele (->required(), ->prefix('R$') etc.).
     *
     * Não usa o magic helper `$money()` do Alpine mask plugin (usado no
     * exemplo oficial do Filament) — decompilado o código-fonte dele
     * (vendor/livewire/livewire/dist/livewire.esm.js) e ele NÃO é um
     * formatador: é um gerador de padrão que só acrescenta os placeholders
     * de centavos ao padrão quando o valor bruto atual JÁ contém o
     * caractere delimitador digitado. Bug real reproduzido pelo usuário
     * (2026-08-21): "91,90" virou "9.190" ao perder o foco. Dois gatilhos
     * reais encontrados: (1) o padrão evolui de forma instável enquanto o
     * usuário digita a vírgula manualmente fora da ordem estritamente
     * incremental que o algoritmo assume; (2) num form de edição, o valor
     * inicial vem do banco em ponto decimal ("91.90", via cast decimal:2),
     * e como o delimitador configurado é vírgula, o algoritmo nunca
     * reconhece esse ponto como separador — descarta a parte decimal do
     * padrão inteiramente.
     *
     * Em vez disso, geramos o padrão nós mesmos (mesma técnica já usada e
     * comprovada em phone(), acima): conta só os DÍGITOS do valor atual via
     * regex (ignora qualquer caractere que o usuário tenha digitado no
     * lugar errado, vírgula ou ponto) e monta "9.999,99" dinamicamente —
     * robusto tanto a digitação livre quanto a um valor pré-existente em
     * outro formato.
     *
     * Não chama ->numeric(): esse método liga um `NumberStateCast` do
     * Filament que aplica `floatval()` no valor a cada sincronização de
     * estado — ANTES do afterStateUpdated() abaixo rodar — truncando
     * "1.234,56" para 1.234 (floatval para no primeiro caractere inválido,
     * a vírgula) antes de eu conseguir normalizar. Por isso aplicamos a
     * regra `numeric` e o inputmode manualmente, sem o cast.
     *
     * Também não usa o stripCharacters() nativo do Filament: ele removeria
     * TODOS os pontos indiscriminadamente, o que corromperia um valor já em
     * formato numérico puro (ex.: "45.00" vindo de um fillForm()/teste
     * viraria "4500"). Em vez disso, normalizeMoneyState() só mexe em
     * strings que contêm vírgula — sinal inequívoco de que vieram da
     * máscara — deixando int/float/string-com-ponto intocados.
     */
    public static function money(TextInput $input): TextInput
    {
        $name = $input->getName();

        return $input
            ->mask(RawJs::make(<<<'JS'
                '9'.repeat(Math.max($input.replace(/\D/g, '').length - 2, 1)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',99'
            JS))
            ->inputMode('decimal')
            ->rule('numeric')
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Set $set, mixed $state) => $set($name, self::normalizeMoneyState($state)))
            ->dehydrateStateUsing(fn (mixed $state) => self::normalizeMoneyState($state));
    }

    public static function normalizeMoneyState(mixed $state): mixed
    {
        if (! is_string($state) || ! str_contains($state, ',')) {
            return $state;
        }

        return str_replace(',', '.', str_replace('.', '', $state));
    }
}

<?php

namespace App\Filament\Support;

use App\Rules\ValidGtin;
use App\Rules\ValidNcm;
use App\Support\CurrentTenant;
use App\Support\FeatureKey;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Campos fiscais do produto (NFC-e, Simples Nacional), compartilhados entre o
 * ProductForm, o padrão fiscal da categoria (CategoryForm) e a ação em lote
 * "Definir dados fiscais". Só aparecem para tenants com a feature
 * `nfe_emissao` — sem ela, produto e categoria ficam exatamente como antes.
 */
final class ProductFiscalFields
{
    public static function tenantUsesFiscal(): bool
    {
        return CurrentTenant::get()?->hasFeature(FeatureKey::NFE_EMISSAO) ?? false;
    }

    public static function section(bool $isCategoryDefault): Section
    {
        return Section::make($isCategoryDefault ? 'Padrão fiscal da categoria' : 'Dados fiscais')
            ->description($isCategoryDefault
                ? 'Valores usados pelos produtos desta categoria que não tiverem dado fiscal próprio (a subcategoria herda da categoria pai o que não preencher).'
                : 'Necessários para emitir NFC-e (Simples Nacional). Campo vazio herda o padrão fiscal da categoria.')
            ->columns(3)
            ->collapsed()
            ->visible(fn (): bool => self::tenantUsesFiscal())
            ->schema(self::fields(withGtin: ! $isCategoryDefault));
    }

    /**
     * @return list<TextInput|Select>
     */
    public static function fields(bool $withGtin): array
    {
        return [
            TextInput::make('ncm')
                ->label('NCM')
                ->mask('9999.99.99')
                ->stripCharacters('.')
                ->rule(new ValidNcm)
                ->maxLength(8)
                ->helperText('8 dígitos.'),
            TextInput::make('cfop')
                ->label('CFOP')
                ->rule('regex:/^5\d{3}$/')
                ->validationMessages(['regex' => 'CFOP inválido: a NFC-e usa CFOP de operação interna (5xxx).'])
                ->maxLength(4)
                ->helperText('Ex.: 5102 (venda de mercadoria).'),
            Select::make('csosn')
                ->label('CSOSN')
                ->options(self::csosnOptions())
                ->searchable(),
            Select::make('origin_code')
                ->label('Origem da mercadoria')
                ->options(self::originOptions())
                ->searchable(),
            Select::make('commercial_unit')
                ->label('Unidade comercial')
                ->options(self::unitOptions())
                ->searchable(),
            TextInput::make('cest')
                ->label('CEST')
                ->mask('99.999.99')
                ->stripCharacters('.')
                ->rule('regex:/^\d{7}$/')
                ->validationMessages(['regex' => 'CEST inválido: informe os 7 dígitos.'])
                ->maxLength(9)
                ->helperText('Opcional.'),
            ...($withGtin ? [
                TextInput::make('gtin')
                    ->label('GTIN (código de barras)')
                    ->stripCharacters([' ', '-', '.'])
                    ->rule(new ValidGtin)
                    ->maxLength(14)
                    ->helperText('Opcional. Sem código, a nota usa "SEM GTIN".'),
            ] : []),
            TextInput::make('icms_rate')
                ->label('Alíquota ICMS (%)')
                ->numeric()->minValue(0)->maxValue(100)->rule('decimal:0,2')->suffix('%')
                ->helperText('Opcional.'),
            TextInput::make('pis_rate')
                ->label('Alíquota PIS (%)')
                ->numeric()->minValue(0)->maxValue(100)->rule('decimal:0,2')->suffix('%')
                ->helperText('Opcional.'),
            TextInput::make('cofins_rate')
                ->label('Alíquota COFINS (%)')
                ->numeric()->minValue(0)->maxValue(100)->rule('decimal:0,2')->suffix('%')
                ->helperText('Opcional.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function csosnOptions(): array
    {
        return [
            '101' => '101 — Tributada com permissão de crédito',
            '102' => '102 — Tributada sem permissão de crédito',
            '103' => '103 — Isenção do ICMS (faixa de receita bruta)',
            '201' => '201 — Com crédito e cobrança do ICMS por substituição tributária',
            '202' => '202 — Sem crédito e com cobrança do ICMS por substituição tributária',
            '203' => '203 — Isenção (faixa de receita) e cobrança por substituição tributária',
            '300' => '300 — Imune',
            '400' => '400 — Não tributada pelo Simples Nacional',
            '500' => '500 — ICMS cobrado anteriormente por substituição tributária',
            '900' => '900 — Outros',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function originOptions(): array
    {
        return [
            '0' => '0 — Nacional',
            '1' => '1 — Estrangeira (importação direta)',
            '2' => '2 — Estrangeira (adquirida no mercado interno)',
            '3' => '3 — Nacional (conteúdo de importação entre 40% e 70%)',
            '4' => '4 — Nacional (processos produtivos básicos)',
            '5' => '5 — Nacional (conteúdo de importação até 40%)',
            '6' => '6 — Estrangeira (importação direta, sem similar nacional)',
            '7' => '7 — Estrangeira (mercado interno, sem similar nacional)',
            '8' => '8 — Nacional (conteúdo de importação acima de 70%)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function unitOptions(): array
    {
        return [
            'UN' => 'UN — Unidade',
            'KG' => 'KG — Quilograma',
            'G' => 'G — Grama',
            'L' => 'L — Litro',
            'ML' => 'ML — Mililitro',
            'M' => 'M — Metro',
            'PC' => 'PC — Peça',
            'PCT' => 'PCT — Pacote',
            'CX' => 'CX — Caixa',
        ];
    }
}

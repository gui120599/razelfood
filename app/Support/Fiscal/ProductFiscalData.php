<?php

namespace App\Support\Fiscal;

/**
 * Dado fiscal EFETIVO de um produto (já resolvido produto → categoria →
 * categoria-pai). Obrigatórios para a NFC-e no Simples Nacional: NCM, CFOP,
 * CSOSN, origem e unidade comercial; CEST, GTIN e alíquotas são opcionais.
 */
final readonly class ProductFiscalData
{
    /**
     * Campos que um produto pode herdar da categoria (GTIN é só do produto).
     *
     * @var list<string>
     */
    public const INHERITABLE_FIELDS = ['ncm', 'cfop', 'csosn', 'origin_code', 'commercial_unit', 'cest', 'icms_rate', 'pis_rate', 'cofins_rate'];

    /**
     * Campo => rótulo, dos obrigatórios.
     *
     * @var array<string, string>
     */
    public const REQUIRED_FIELDS = [
        'ncm' => 'NCM',
        'cfop' => 'CFOP',
        'csosn' => 'CSOSN',
        'origin_code' => 'Origem',
        'commercial_unit' => 'Unidade',
    ];

    public function __construct(
        public ?string $ncm,
        public ?string $cfop,
        public ?string $csosn,
        public ?string $originCode,
        public ?string $commercialUnit,
        public ?string $cest,
        public ?string $gtin,
        public ?string $icmsRate,
        public ?string $pisRate,
        public ?string $cofinsRate,
    ) {}

    /**
     * Rótulos dos campos obrigatórios que ainda faltam.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $values = [
            'ncm' => $this->ncm,
            'cfop' => $this->cfop,
            'csosn' => $this->csosn,
            'origin_code' => $this->originCode,
            'commercial_unit' => $this->commercialUnit,
        ];

        return array_values(array_map(
            fn (string $field): string => self::REQUIRED_FIELDS[$field],
            array_keys(array_filter($values, fn (?string $value): bool => ! filled($value))),
        ));
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }
}

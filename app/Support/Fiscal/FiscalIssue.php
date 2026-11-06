<?php

namespace App\Support\Fiscal;

/**
 * Uma pendência do checklist de prontidão fiscal.
 */
final readonly class FiscalIssue
{
    public const SECTION_TENANT = 'tenant';

    public const SECTION_PRODUCTS = 'products';

    public const SECTION_PAYMENTS = 'payments';

    /**
     * @param  list<string>  $items  Detalhes (ex.: nomes de produtos), já limitados.
     */
    public function __construct(
        public string $section,
        public string $message,
        public array $items = [],
        public int $count = 1,
    ) {}
}

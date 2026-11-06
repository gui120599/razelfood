<?php

namespace App\Support\Fiscal;

/**
 * Resultado de FiscalReadiness: a lista do que falta para o tenant poder
 * emitir NFC-e. Vazia = pronto.
 */
final readonly class FiscalReadinessReport
{
    /**
     * @param  list<FiscalIssue>  $issues
     */
    public function __construct(public array $issues) {}

    public function ready(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return list<FiscalIssue>
     */
    public function issuesIn(string $section): array
    {
        return array_values(array_filter($this->issues, fn (FiscalIssue $issue): bool => $issue->section === $section));
    }
}

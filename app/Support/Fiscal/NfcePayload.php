<?php

namespace App\Support\Fiscal;

/**
 * Corpo pronto para o POST da NFE.io + o motivo de o comprador ter ficado de fora (se ficou).
 */
final readonly class NfcePayload
{
    /**
     * @param  array<string, mixed>  $body
     * @param  list<array<string, mixed>>  $lines  Snapshot dos itens como enviados (gravado em fiscal_document_items).
     */
    public function __construct(
        public array $body,
        public ?string $buyerOmissionReason,
        public array $lines = [],
    ) {}
}

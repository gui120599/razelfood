<?php

namespace App\Support\Fiscal;

/**
 * Corpo pronto para o POST da NFE.io + o motivo de o comprador ter ficado de fora (se ficou).
 */
final readonly class NfcePayload
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public array $body,
        public ?string $buyerOmissionReason,
    ) {}
}

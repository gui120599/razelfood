<?php

namespace App\Enums;

use Illuminate\Support\Facades\Log;

/**
 * Estado interno do documento fiscal. O texto cru da NFE.io fica em
 * `provider_status`; `fromProvider()` traduz.
 */
enum FiscalDocumentStatus: string
{
    case Reserved = 'reserved';
    case FailedToSend = 'failed_to_send';
    case Processing = 'processing';
    case Issued = 'issued';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case CancelRequested = 'cancel_requested';
    case CancelFailed = 'cancel_failed';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Número reservado',
            self::FailedToSend => 'Falha no envio',
            self::Processing => 'Processando',
            self::Issued => 'Emitida',
            self::Failed => 'Rejeitada',
            self::Cancelled => 'Cancelada',
            self::CancelRequested => 'Cancelamento solicitado',
            self::CancelFailed => 'Falha no cancelamento',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Issued => 'success',
            self::Processing, self::Reserved, self::CancelRequested => 'warning',
            self::Failed, self::FailedToSend, self::CancelFailed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Traduz o status da NFE.io. Status desconhecido é tratado como em
     * processamento (e registrado), nunca como emitido.
     */
    public static function fromProvider(?string $providerStatus): self
    {
        return match ($providerStatus) {
            'Processing', 'Created' => self::Processing,
            'Issued' => self::Issued,
            'Cancelled' => self::Cancelled,
            'Error', 'IssueFailed', 'Failed' => self::Failed,
            'CancelFailed' => self::CancelFailed,
            default => self::unknownProviderStatus($providerStatus),
        };
    }

    private static function unknownProviderStatus(?string $providerStatus): self
    {
        Log::warning('Status fiscal desconhecido recebido da NFE.io.', ['provider_status' => $providerStatus]);

        return self::Processing;
    }

    /**
     * Documento "vivo": impede cancelar a venda e emitir outro documento (RN-FIS-16).
     */
    public function isAlive(): bool
    {
        return in_array($this, [self::Issued, self::Processing, self::CancelRequested, self::CancelFailed], true);
    }

    /**
     * @return list<string>
     */
    public static function aliveValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isAlive()),
        ));
    }

    /**
     * Notas AUTORIZADAS e ainda de pé na SEFAZ: emitida e também com cancelamento solicitado ou
     * falho (continuam válidas até a confirmação). Base do relatório fiscal e do contador da central.
     *
     * @return list<string>
     */
    public static function standingValues(): array
    {
        return [self::Issued->value, self::CancelRequested->value, self::CancelFailed->value];
    }

    /**
     * O número já foi consumido na SEFAZ e não pode ser reaproveitado.
     */
    public function consumesNumber(): bool
    {
        return in_array($this, [self::Issued, self::Cancelled, self::CancelRequested, self::CancelFailed], true);
    }
}

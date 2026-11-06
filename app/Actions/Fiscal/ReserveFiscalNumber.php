<?php

namespace App\Actions\Fiscal;

use App\Enums\FiscalDocumentDecision;
use App\Enums\FiscalDocumentStatus;
use App\Exceptions\FiscalDocumentException;
use App\Models\FiscalDocument;
use App\Models\FiscalNumberSequence;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantFiscalSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reserva o número fiscal ANTES do POST (RN-FIS-01..03). Transação curta, sem HTTP:
 * trava a venda e a sequência (única por CNPJ + ambiente + modelo + série, entre
 * tenants — MT-05), reaproveita a reserva ainda não consumida da venda (reenvio não
 * queima número) e só abre número novo quando o anterior foi consumido (nota cancelada).
 * O unique (sequence_id, number) é a segunda barreira contra duplicidade.
 */
class ReserveFiscalNumber
{
    private const MODEL = 65;

    /** Estados cujo número NÃO foi consumido na SEFAZ e pode ser reaproveitado. */
    private const REUSABLE = [FiscalDocumentStatus::Reserved, FiscalDocumentStatus::FailedToSend, FiscalDocumentStatus::Failed];

    /**
     * @throws FiscalDocumentException
     */
    public function __invoke(Sale $sale, Tenant $tenant, TenantFiscalSettings $settings, FiscalDocumentDecision $decision, ?User $user = null): FiscalDocument
    {
        $issuerCnpj = preg_replace('/\D/', '', (string) $tenant->cnpj);

        if (strlen($issuerCnpj) !== 14) {
            throw new FiscalDocumentException('O CNPJ do estabelecimento não está preenchido corretamente.');
        }

        $environment = $settings->environment;
        $series = (int) $settings->nfce_series;

        // A linha da sequência é criada FORA da transação (autocommit): um INSERT IGNORE em
        // chave duplicada dentro dela seguraria lock compartilhado e duas reservas simultâneas
        // travariam uma à outra no FOR UPDATE seguinte.
        DB::table('fiscal_number_sequences')->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'issuer_cnpj' => $issuerCnpj,
            'environment' => $environment->value,
            'model' => self::MODEL,
            'series' => $series,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($sale, $tenant, $issuerCnpj, $environment, $series, $decision, $user): FiscalDocument {
            // Venda primeiro, sequência depois (ordem de lock do projeto). O lock da venda já
            // serializa as reservas dela: os documentos são lidos SEM lock (um FOR UPDATE por
            // sale_id travaria o intervalo do índice de todas as vendas novas do tenant).
            Sale::query()->withoutGlobalScope(TenantScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            $documents = FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)->where('sale_id', $sale->id)
                ->orderByDesc('id')->get();

            if ($documents->contains(fn (FiscalDocument $document): bool => $document->isLive())) {
                throw new FiscalDocumentException('Esta venda já tem uma NFC-e emitida ou em processamento.');
            }

            $reusable = $documents->first(fn (FiscalDocument $document): bool => (in_array($document->status, self::REUSABLE, true) || $document->isStaleSending())
                && $document->environment === $environment
                && $document->series === $series);

            if ($reusable !== null) {
                $reusable->update([
                    'status' => FiscalDocumentStatus::Reserved,
                    'decision' => $decision,
                    'user_id' => $user?->id,
                    'provider_id' => null,
                    'provider_status' => null,
                    'authorized_at' => null,
                    'cancelled_at' => null,
                    'error_message' => null,
                ]);

                return $reusable;
            }

            $sequence = FiscalNumberSequence::query()
                ->where('issuer_cnpj', $issuerCnpj)->where('environment', $environment->value)
                ->where('model', self::MODEL)->where('series', $series)
                ->lockForUpdate()->firstOrFail();

            $number = $sequence->last_number + 1;
            $sequence->update(['last_number' => $number]);

            return FiscalDocument::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'sequence_id' => $sequence->id,
                'environment' => $environment,
                'model' => self::MODEL,
                'series' => $series,
                'number' => $number,
                'status' => FiscalDocumentStatus::Reserved,
                'decision' => $decision,
                'user_id' => $user?->id,
            ]);
        }, attempts: 3);
    }
}

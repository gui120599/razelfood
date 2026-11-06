<?php

namespace App\Actions\Sales;

use App\Actions\Fiscal\CancelFiscalDocument;
use App\Enums\FiscalDocumentStatus;
use App\Enums\SaleStatus;
use App\Exceptions\FiscalDocumentException;
use App\Exceptions\SaleException;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento de venda que sabe lidar com NFC-e (ação combinada). Sem nota viva, cancela
 * direto (CancelSale). Com nota AUTORIZADA, grava o cancelamento como pendente na venda,
 * pede o cancelamento da nota e a venda é cancelada sozinha quando a NFE.io confirmar
 * (ApplyFiscalProviderStatus ⇒ CompleteSaleCancellationJob). O pendente é gravado ANTES do
 * pedido à NFE.io para um webhook rápido nunca chegar antes dele; se o pedido for recusado,
 * o pendente é desfeito e a venda segue intacta.
 */
class CancelSaleWithFiscalDocument
{
    public function __construct(
        private readonly CancelSale $cancelSale,
        private readonly CancelFiscalDocument $cancelFiscalDocument,
    ) {}

    /**
     * @return array{sale: Sale, pending: bool} `pending` = a venda só será cancelada quando a nota for.
     *
     * @throws SaleException
     */
    public function __invoke(Sale $sale, string $note, ?User $user = null): array
    {
        $note = trim($note);
        $document = $this->liveDocument($sale);

        if ($document === null) {
            return ['sale' => ($this->cancelSale)($sale, $note, $user), 'pending' => false];
        }

        $this->assertCancellable($document, $user);

        if ($note === '') {
            throw new SaleException('Informe o motivo do cancelamento da venda.');
        }

        if (mb_strlen($note) > 255) {
            throw new SaleException('O motivo do cancelamento pode ter no máximo 255 caracteres.');
        }

        // Um pendente já existente (duplo clique, outro operador) não é sobrescrito nem desfeito por esta chamada.
        $created = $this->markPending($sale, $note, $user);

        if ($document->status === FiscalDocumentStatus::Issued && $created) {
            try {
                ($this->cancelFiscalDocument)($document, $user);
            } catch (FiscalDocumentException $exception) {
                // Recusa definitiva (nada foi pedido): a venda segue intacta. Se o pedido PODE ter
                // chegado à NFE.io, o pendente fica: o webhook/consulta ainda pode confirmar.
                if (! $exception->requestMayHaveBeenSent) {
                    $this->clearPending($sale);
                }

                throw new SaleException(
                    $exception->getMessage().($exception->requestMayHaveBeenSent ? ' O cancelamento da venda ficou aguardando: confirme o status da nota ou use "Desfazer cancelamento pendente".' : ''),
                    previous: $exception,
                );
            }
        }

        return ['sale' => $sale->refresh(), 'pending' => true];
    }

    private function liveDocument(Sale $sale): ?FiscalDocument
    {
        return FiscalDocument::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $sale->tenant_id)->where('sale_id', $sale->id)
            ->live()->orderByDesc('id')->first();
    }

    /**
     * @throws SaleException
     */
    private function assertCancellable(FiscalDocument $document, ?User $user): void
    {
        match ($document->status) {
            FiscalDocumentStatus::Issued => $user?->can('cancel_fiscal_document')
                ? null
                : throw new SaleException('Esta venda tem uma NFC-e autorizada. Só quem pode cancelar notas fiscais consegue cancelá-la (e, com ela, a venda).'),
            FiscalDocumentStatus::CancelRequested => null,
            FiscalDocumentStatus::Processing => throw new SaleException('A NFC-e desta venda está em processamento. Aguarde a autorização (ou use "Atualizar status") para poder cancelá-la.'),
            default => throw new SaleException('O cancelamento da NFC-e desta venda falhou ou está indefinido. Atualize o status da nota ou confira no painel da NFE.io antes de cancelar a venda.'),
        };
    }

    /**
     * @return bool true se gravou o pendente agora; false se já existia um (mantido como está)
     */
    private function markPending(Sale $sale, string $note, ?User $user): bool
    {
        return DB::transaction(function () use ($sale, $note, $user): bool {
            $locked = Sale::query()->withoutGlobalScope(TenantScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Finalized) {
                throw new SaleException("A venda {$locked->displayNumber()} já está cancelada.");
            }

            if ($locked->hasPendingCancellation()) {
                return false;
            }

            $locked->update([
                'pending_cancellation_note' => $note,
                'pending_cancellation_by_user_id' => $user?->id,
                'pending_cancellation_at' => now(),
            ]);

            return true;
        }, attempts: 3);
    }

    /**
     * Desfaz um cancelamento pendente (a nota não foi cancelada e o operador quer voltar atrás).
     */
    public function discardPending(Sale $sale): void
    {
        $this->clearPending($sale);
    }

    private function clearPending(Sale $sale): void
    {
        Sale::query()->withoutGlobalScope(TenantScope::class)->whereKey($sale->id)->update([
            'pending_cancellation_note' => null,
            'pending_cancellation_by_user_id' => null,
            'pending_cancellation_at' => null,
        ]);
    }
}

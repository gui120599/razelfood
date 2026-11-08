<?php

namespace App\Support\Sales;

use App\Exceptions\SaleException;
use App\Models\CardBrand;
use App\Models\PaymentOption;

/**
 * Validação dos pagamentos recebidos de uma venda e cálculo do troco NO SERVIDOR (nunca vem do
 * front). Compartilhada pelo fechamento direto (FinalizeSale) e pelo rascunho
 * (AddSalePayment / FinalizeOpenSale): a regra é uma só. CardBrand e PaymentOption são
 * tenant-scoped: registro de outro estabelecimento nunca é encontrado.
 */
class PaymentLines
{
    private const MAX_CARD_BRAND_LENGTH = 60;

    private const MAX_AUTHORIZATION_LENGTH = 100;

    /** Maior valor que cabe em decimal(10,2), em centavos. */
    private const MAX_CENTS = 9_999_999_999;

    /**
     * Valida um pagamento isolado (forma, valor, bandeira, autorização) — sem olhar o total.
     * `$enforceRequirements` = false deixa passar bandeira/autorização faltando (divisão da conta:
     * o operador completa depois; a exigência vale de novo ao finalizar).
     *
     * @param  array{payment_option_id: int, amount: float|int|string, card_brand?: ?string, card_brand_id?: int|string|null, authorization_code?: ?string}  $payment
     * @return array<string, mixed> Linha pronta (com amount_cents/change_cents = 0), sem tenant_id/sale_id.
     *
     * @throws SaleException
     */
    public function validate(array $payment, ?PaymentOption $option = null, bool $enforceRequirements = true): array
    {
        $option ??= PaymentOption::query()->find((int) $payment['payment_option_id']);

        if ($option === null) {
            throw new SaleException('Forma de pagamento inválida.');
        }

        $amountCents = Money::toCents($payment['amount']);

        if ($amountCents <= 0) {
            throw new SaleException("Informe um valor maior que zero para \"{$option->name}\".");
        }

        if ($amountCents > self::MAX_CENTS) {
            throw new SaleException("O valor informado para \"{$option->name}\" é grande demais.");
        }

        $cardBrand = $this->cleanText($payment['card_brand'] ?? null, self::MAX_CARD_BRAND_LENGTH);
        $registeredBrand = null;

        if (filled($payment['card_brand_id'] ?? null)) {
            $registeredBrand = CardBrand::query()->find((int) $payment['card_brand_id'])
                ?? throw new SaleException("Bandeira de cartão inválida para \"{$option->name}\".");
            $cardBrand = mb_substr($registeredBrand->name, 0, self::MAX_CARD_BRAND_LENGTH);
        }

        $authorizationCode = $this->cleanText($payment['authorization_code'] ?? null, self::MAX_AUTHORIZATION_LENGTH);

        if ($enforceRequirements && $option->requires_card_brand && $cardBrand === null) {
            throw new SaleException("Informe a bandeira do cartão para \"{$option->name}\".");
        }

        if ($enforceRequirements && $option->requires_authorization_code && $authorizationCode === null) {
            throw new SaleException("Informe o código de autorização para \"{$option->name}\".");
        }

        return [
            'payment_option_id' => $option->id,
            'payment_option_name' => $option->name,
            'is_cash' => (bool) $option->is_cash,
            'amount_cents' => $amountCents,
            'change_cents' => 0,
            'card_brand' => $cardBrand,
            'card_brand_id' => $registeredBrand?->id,
            'authorization_code' => $authorizationCode,
        ];
    }

    /**
     * Valida os pagamentos recebidos e calcula o troco no servidor.
     *
     * @param  array<int, array{payment_option_id: int, amount: float|int|string, card_brand?: ?string, card_brand_id?: int|string|null, authorization_code?: ?string}>  $payments
     * @return array<int, array<string, mixed>> Linhas prontas para SalePayment (sem tenant_id/sale_id).
     *
     * @throws SaleException
     */
    public function build(array $payments, int $totalCents): array
    {
        if ($payments === []) {
            if ($totalCents > 0) {
                throw new SaleException('Informe ao menos uma forma de pagamento.');
            }

            return [];
        }

        $options = PaymentOption::query()
            ->whereIn('id', array_map(fn (array $payment): int => (int) $payment['payment_option_id'], $payments))
            ->get()
            ->keyBy('id');

        $lines = [];
        $receivedCents = 0;
        $cashCents = 0;

        foreach ($payments as $payment) {
            $line = $this->validate($payment, $options->get((int) $payment['payment_option_id']));

            $receivedCents += $line['amount_cents'];
            $cashCents += $line['is_cash'] ? $line['amount_cents'] : 0;
            $lines[] = $line;
        }

        if ($receivedCents < $totalCents) {
            throw new SaleException('Pagamento insuficiente: faltam '.Money::format($totalCents - $receivedCents).'.');
        }

        $changeCents = $receivedCents - $totalCents;

        if ($changeCents > $cashCents) {
            throw new SaleException('O valor recebido passa do total em '.Money::format($changeCents).', mas o troco só pode sair de pagamento em dinheiro.');
        }

        // Troco sai das linhas em dinheiro, da última para a primeira.
        foreach (array_reverse(array_keys($lines)) as $index) {
            if ($changeCents === 0) {
                break;
            }

            if (! $lines[$index]['is_cash']) {
                continue;
            }

            $applied = min($changeCents, $lines[$index]['amount_cents']);
            $lines[$index]['change_cents'] = $applied;
            $changeCents -= $applied;
        }

        return array_map(function (array $line): array {
            $line['amount'] = Money::fromCents($line['amount_cents']);
            $line['change_amount'] = Money::fromCents($line['change_cents']);
            unset($line['amount_cents'], $line['change_cents']);

            return $line;
        }, $lines);
    }

    private function cleanText(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}

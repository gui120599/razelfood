<?php

namespace App\Support\Sales;

/**
 * Resumo do fechamento (tudo em centavos): quanto falta, quanto sobra de troco
 * e se o troco é possível (só pode sair de pagamento em dinheiro). Usado pela
 * tela de fechamento como PREVIEW; a regra que vale é a de FinalizeSale.
 */
final readonly class PaymentSummary
{
    public function __construct(
        public int $totalCents,
        public int $receivedCents,
        public int $cashCents,
    ) {}

    /**
     * @param  iterable<int, array{amount_cents: int, is_cash: bool}>  $lines
     */
    public static function fromLines(int $totalCents, iterable $lines): self
    {
        $received = 0;
        $cash = 0;

        foreach ($lines as $line) {
            $received += $line['amount_cents'];
            $cash += $line['is_cash'] ? $line['amount_cents'] : 0;
        }

        return new self($totalCents, $received, $cash);
    }

    public function remainingCents(): int
    {
        return max(0, $this->totalCents - $this->receivedCents);
    }

    public function changeCents(): int
    {
        return max(0, $this->receivedCents - $this->totalCents);
    }

    public function canGiveChange(): bool
    {
        return $this->changeCents() <= $this->cashCents;
    }

    public function isCovered(): bool
    {
        return $this->receivedCents >= $this->totalCents && $this->canGiveChange();
    }
}

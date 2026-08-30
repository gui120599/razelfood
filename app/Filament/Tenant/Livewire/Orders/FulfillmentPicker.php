<?php

namespace App\Filament\Tenant\Livewire\Orders;

use App\Models\DeliveryOption;
use App\Models\PaymentOption;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Seleção de entrega + formas de pagamento combinadas (cada uma com valor
 * e, se dinheiro, troco daquela parcela) — mesmos selects/regra do
 * Checkout público, adaptado pro painel. O preview de taxa de entrega/total
 * fica na própria AttendOrder (que já centraliza carrinho + dados do
 * cliente, únicos ingredientes que faltam aqui pra calcular a taxa).
 */
class FulfillmentPicker extends Component
{
    public ?int $deliveryOptionId = null;

    /** @var array<int, array{payment_option_id: ?int, amount: ?string, change_for: ?string}> */
    public array $payments = [];

    /**
     * Total do pedido (itens + taxa de entrega), calculado pela AttendOrder e
     * passado de cima a cada render. `#[Reactive]` porque um componente
     * Livewire aninhado NÃO re-renderiza junto do pai por padrão — sem isso o
     * componente ficaria preso ao total do mount (0). Muda de fora sem passar
     * pelo ciclo updating/updated, então o preenchimento do saldo restante é
     * reavaliado no `render()`.
     */
    #[Reactive]
    public float $total = 0;

    /**
     * @param  array<string, mixed>  $initial
     */
    public function mount(array $initial = []): void
    {
        $this->deliveryOptionId = $initial['delivery_option_id'] ?? null;
        $this->payments = ! empty($initial['payments'])
            ? $initial['payments']
            : [$this->blankPaymentLine()];

        $this->autofillRemaining();
        $this->emitChange();
    }

    public function selectDeliveryOption(int $id): void
    {
        $this->deliveryOptionId = $id;
        $this->emitChange();
    }

    public function addPaymentLine(): void
    {
        $this->payments[] = $this->blankPaymentLine();
        $this->autofillRemaining();
        $this->emitChange();
    }

    public function removePaymentLine(int $index): void
    {
        if (count($this->payments) <= 1) {
            return;
        }

        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
        $this->autofillRemaining();
        $this->emitChange();
    }

    public function selectPaymentOptionForLine(int $index, int $paymentOptionId): void
    {
        if (! isset($this->payments[$index])) {
            return;
        }

        $this->payments[$index]['payment_option_id'] = $paymentOptionId;
        $this->payments[$index]['change_for'] = null;
        $this->emitChange();
    }

    private function blankPaymentLine(): array
    {
        return ['payment_option_id' => null, 'amount' => null, 'change_for' => null];
    }

    public function isLineCash(int $index): bool
    {
        $id = $this->payments[$index]['payment_option_id'] ?? null;

        return $id ? (bool) PaymentOption::find($id)?->is_cash : false;
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'payments.') && str_ends_with($name, '.amount')) {
            $this->autofillRemaining();
        }

        $this->emitChange();
    }

    /**
     * Preenche o saldo restante (total - soma já digitada) na primeira linha
     * de pagamento ainda em branco — poupa o atendente de calcular de cabeça
     * ao dividir o pagamento, e já deixa a linha única com o total do pedido.
     *
     * @return bool se alguma linha em branco foi preenchida
     */
    private function autofillRemaining(): bool
    {
        if ($this->total <= 0) {
            return false;
        }

        $sumFilled = 0.0;
        $blankIndex = null;

        foreach ($this->payments as $index => $line) {
            if (blank($line['amount'] ?? null)) {
                $blankIndex ??= $index;

                continue;
            }

            $sumFilled += $this->parseBrl($line['amount']);
        }

        if ($blankIndex === null) {
            return false;
        }

        $remaining = round($this->total - $sumFilled, 2);

        if ($remaining <= 0) {
            return false;
        }

        $this->payments[$blankIndex]['amount'] = number_format($remaining, 2, ',', '.');

        return true;
    }

    private function emitChange(): void
    {
        $this->dispatch('order-fulfillment-changed', data: [
            'delivery_option_id' => $this->deliveryOptionId,
            'payments' => $this->payments,
        ]);
    }

    private function parseBrl(?string $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        return (float) str_replace(['.', ','], ['', '.'], $value);
    }

    #[Computed]
    public function selectedDeliveryOption(): ?DeliveryOption
    {
        return $this->deliveryOptionId ? DeliveryOption::find($this->deliveryOptionId) : null;
    }

    #[Computed]
    public function requiresAddress(): bool
    {
        return (bool) $this->selectedDeliveryOption?->requires_address;
    }

    #[Computed]
    public function deliveryOptions(): Collection
    {
        return DeliveryOption::where('show_in_menu', true)->orderBy('name')->get();
    }

    #[Computed]
    public function paymentOptions(): Collection
    {
        return PaymentOption::where('show_in_menu', true)->orderBy('name')->get();
    }

    public function render()
    {
        // `total` reativo muda de fora sem passar por updating/updated — então
        // o preenchimento é reavaliado aqui a cada render, inclusive nos que a
        // página pai dispara ao mudar carrinho/entrega. Só re-emite pro pai se
        // realmente preencheu algo (evita loop de request pai↔filho).
        if ($this->autofillRemaining()) {
            $this->emitChange();
        }

        return view('filament.tenant.livewire.orders.fulfillment-picker');
    }
}

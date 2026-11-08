<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Entrega e pagamento</h3>

    <div>
        <label class="text-xs text-gray-500 dark:text-gray-400">Opção de entrega</label>
        <select wire:change="selectDeliveryOption($event.target.value)"
            class="w-full mt-1 px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">Selecione...</option>
            @foreach ($this->deliveryOptions as $option)
                <option value="{{ $option->id }}" @selected($deliveryOptionId == $option->id)>{{ $option->name }}</option>
            @endforeach
        </select>
        @if ($this->deliveryOptions->isEmpty())
            <p class="mt-1 text-xs text-red-600 dark:text-red-400">
                Nenhuma opção de entrega cadastrada. Cadastre em Opções de Entrega.
            </p>
        @endif
    </div>

    <div class="space-y-2">
        <div class="flex items-center justify-between">
            <label class="text-xs text-gray-500 dark:text-gray-400">Forma(s) de pagamento combinada(s)</label>
            <button type="button" wire:click="addPaymentLine"
                class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
                + Adicionar
            </button>
        </div>

        @foreach ($payments as $indice => $linha)
            <div wire:key="payment-line-{{ $indice }}" class="space-y-1">
            <div class="flex items-start gap-2">
                <select wire:change="selectPaymentOptionForLine({{ $indice }}, $event.target.value)"
                    class="flex-1 px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option value="">Escolha...</option>
                    @foreach ($this->paymentOptions as $option)
                        <option value="{{ $option->id }}" @selected($linha['payment_option_id'] == $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>

                <div class="w-24">
                    <input type="text" wire:model.live.debounce.500ms="payments.{{ $indice }}.amount"
                        inputmode="decimal"
                        x-on:input="$el.value = window.maskMoney($el.value)"
                        class="w-full px-2 py-1.5 text-xs text-right border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>

                @if ($this->isLineCash($indice))
                    <div class="w-24">
                        <input type="text" wire:model.live.debounce.500ms="payments.{{ $indice }}.change_for" placeholder="Troco p/"
                            inputmode="decimal"
                            x-on:input="$el.value = window.maskMoney($el.value)"
                            class="w-full px-2 py-1.5 text-xs text-right border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                @endif

                @if (count($payments) > 1)
                    <button type="button" wire:click="removePaymentLine({{ $indice }})"
                        class="text-gray-400 hover:text-red-500 mt-1.5">
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                    </button>
                @endif
            </div>

            </div>
        @endforeach
    </div>
</div>

@script
<script>
    window.maskMoney = (value) => {
        const digits = value.replace(/\D/g, '');

        if (!digits) {
            return '';
        }

        return (parseInt(digits, 10) / 100)
            .toFixed(2)
            .replace('.', ',')
            .replace(/(\d)(?=(\d{3})+(?=,))/g, '$1.');
    };
</script>
@endscript

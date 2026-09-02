<div class="space-y-4">
    <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Como o cliente vai receber?</label>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($this->deliveryOptions as $option)
                <button
                    type="button"
                    wire:click="selectDeliveryOption({{ $option->id }})"
                    @class([
                        'rounded-lg border px-3 py-2 text-center text-sm transition',
                        'border-rf-orange-600 bg-rf-orange-600/10 text-rf-orange-700 dark:text-white' => $deliveryOptionId === $option->id,
                        'border-gray-200 bg-gray-50 text-gray-600 hover:bg-gray-100 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $deliveryOptionId !== $option->id,
                    ])
                >
                    {{ $option->name }}
                </button>
            @endforeach
        </div>
    </div>

    <div>
        <div class="mb-1 flex items-center justify-between">
            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Forma(s) de pagamento</label>
            <button type="button" wire:click="addPaymentLine" class="flex cursor-pointer items-center gap-1 text-xs font-semibold text-rf-orange-600 hover:text-rf-orange-700">
                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                Adicionar
            </button>
        </div>

        <div class="space-y-2">
            @foreach ($payments as $index => $line)
                <div wire:key="payment-line-{{ $index }}" class="rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-center gap-2">
                        <select
                            wire:change="selectPaymentOptionForLine({{ $index }}, $event.target.value)"
                            class="flex-1 appearance-none rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 focus:ring-2 focus:ring-rf-orange-600 [&_option]:bg-white dark:border-white/10 dark:bg-gray-800 dark:text-white dark:[color-scheme:dark] dark:[&_option]:bg-gray-900"
                        >
                            <option value="" @selected(blank($line['payment_option_id']))>Escolha...</option>
                            @foreach ($this->paymentOptions as $option)
                                <option value="{{ $option->id }}" @selected($line['payment_option_id'] == $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>

                        <div class="relative w-28 shrink-0">
                            <span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 dark:text-gray-500">R$</span>
                            <input
                                type="text"
                                inputmode="numeric"
                                wire:model.live.debounce.500ms="payments.{{ $index }}.amount"
                                x-on:input="$el.value = window.maskMoney($el.value)"
                                placeholder="0,00"
                                class="fi-input w-full rounded-lg border border-gray-300 bg-white py-1.5 pl-7 pr-2 text-right text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/10 dark:text-white dark:placeholder:text-gray-500"
                            >
                        </div>

                        @if (count($payments) > 1)
                            <button type="button" wire:click="removePaymentLine({{ $index }})" class="text-gray-400 hover:text-rf-danger">
                                <x-heroicon-o-trash class="h-4 w-4" />
                            </button>
                        @endif
                    </div>

                    @if ($this->isLineCash($index))
                        <div class="relative mt-2">
                            <span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 dark:text-gray-500">R$</span>
                            <input
                                type="text"
                                inputmode="numeric"
                                wire:model.live.debounce.500ms="payments.{{ $index }}.change_for"
                                x-on:input="$el.value = window.maskMoney($el.value)"
                                placeholder="Troco para (deixe em branco se não precisar)"
                                class="fi-input block w-full rounded-lg border border-gray-300 bg-white py-1.5 pl-7 pr-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/10 dark:text-white dark:placeholder:text-gray-500"
                            >
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
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

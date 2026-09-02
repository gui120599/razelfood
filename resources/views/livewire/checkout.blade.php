<div class="space-y-4 pb-10">
    <div class="flex items-center gap-2">
        <a href="{{ route('menu.index') }}" class="text-gray-400 transition hover:text-white">
            <x-heroicon-o-chevron-left class="h-6 w-6" />
        </a>
        <h1 class="text-lg font-bold text-white">Finalizar pedido</h1>
    </div>

    {{-- 1. Dados do cliente --}}
    <div class="space-y-3 rounded-xl border border-white/10 bg-gray-900 p-4">
        <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
            <x-heroicon-o-user class="h-4 w-4" />
            Seus dados
        </h2>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400">Telefone / WhatsApp</label>
            <input type="tel" wire:model.live.debounce.300ms="phone" data-field="phone"
                   x-on:input="$el.value = window.maskPhone($el.value)"
                   placeholder="(11) 98888-7777" maxlength="15"
                   class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none">
            @error('phone') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            @if ($clientFound)
                <p class="mt-1 flex items-center gap-1 text-xs text-green-400">
                    <x-heroicon-o-check-circle class="h-4 w-4" /> Encontramos seu cadastro — confira os dados abaixo.
                </p>
            @endif
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400">Nome</label>
            <input type="text" wire:model="name" data-field="name"
                   class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none">
            @error('name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        @if ($this->requiresCpf)
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400">CPF</label>
                <input type="text" wire:model="cpf" data-field="cpf" inputmode="numeric"
                       x-on:input="$el.value = window.maskCpf($el.value)"
                       placeholder="000.000.000-00" maxlength="14"
                       class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none">
                @error('cpf') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>

    {{-- 2. Opção de entrega --}}
    @if ($this->deliveryOptions->isNotEmpty())
        <div class="rounded-xl border border-white/10 bg-gray-900 p-4">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                <x-heroicon-o-truck class="h-4 w-4" />
                Como deseja receber?
            </h2>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($this->deliveryOptions as $option)
                    <button type="button" wire:key="delivery-{{ $option->id }}"
                            wire:click="$set('deliveryOptionId', {{ $option->id }})"
                            @class([
                                'flex flex-col items-center gap-1 rounded-lg border px-3 py-2.5 text-center text-xs font-semibold transition cursor-pointer',
                                'border-[var(--tenant-primary)] bg-[var(--tenant-primary)]/10 text-[var(--tenant-primary)]' => (string) $deliveryOptionId === (string) $option->id,
                                'border-gray-700 bg-gray-800 text-gray-300' => (string) $deliveryOptionId !== (string) $option->id,
                            ])>
                        <x-heroicon-o-truck class="h-5 w-5" />
                        {{ $option->name }}
                        @if ((float) $option->delivery_fee > 0)
                            <span class="font-normal text-gray-400">Taxa conforme o endereço</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 3. Endereço (só aparece pra opções que exigem endereço, ex.: entrega por motoboy) --}}
    @if ($this->requiresAddress)
        @php($fieldClass = 'w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none')
        <div class="space-y-3 rounded-xl border border-white/10 bg-gray-900 p-4">
            <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                <x-heroicon-o-map-pin class="h-4 w-4" />
                Endereço de entrega
            </h2>

            @if ($this->addressIsRestricted)
                <p class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-[11px] leading-snug text-gray-400">
                    <x-heroicon-o-information-circle class="mr-0.5 inline h-3.5 w-3.5 align-text-bottom" />
                    Entregamos apenas nas cidades e bairros já cadastrados pela loja. Não encontrou o seu? Combine a entrega pelo WhatsApp.
                </p>
            @endif

            {{-- Passo 1: CEP primeiro --}}
            <div>
                <label class="mb-1 block text-xs text-gray-500">CEP</label>
                <input type="text" wire:model="zipCode" wire:blur="lookupCep" data-field="zipCode"
                       x-on:input="$el.value = window.maskCep($el.value)"
                       placeholder="00000-000" maxlength="9" inputmode="numeric"
                       class="max-w-[160px] {{ $fieldClass }}">
                @if ($cepNotFound)
                    <p class="mt-1 text-xs text-yellow-400">CEP não encontrado — preencha o endereço abaixo.</p>
                @endif
                @if (! $addressUnlocked)
                    <button type="button" wire:click="revealManualAddress"
                            class="mt-2 flex cursor-pointer items-center gap-1 text-xs font-semibold text-[var(--tenant-primary)]">
                        <x-heroicon-o-question-mark-circle class="h-3.5 w-3.5" /> Não sei meu CEP
                    </button>
                @endif
            </div>

            @if ($addressUnlocked)
                {{-- Passo 2: estado + cidade --}}
                @if ($this->addressIsRestricted)
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">UF</label>
                            <select wire:model.live="state" data-field="state" class="{{ $fieldClass }}">
                                <option value="">—</option>
                                @foreach ($this->servedStates as $servedState)
                                    <option value="{{ $servedState->uf }}">{{ $servedState->uf }}</option>
                                @endforeach
                            </select>
                            @error('state') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs text-gray-500">Cidade</label>
                            <x-menu.combobox
                                wire:key="checkout-city-{{ $state }}"
                                data-field="city"
                                name="cityId"
                                :options="$this->cityOptionsForState->pluck('name', 'id')->all()"
                                placeholder="Escolha a cidade…"
                                search-placeholder="Buscar cidade…"
                                :disabled="blank($state)" />
                            @error('city') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs text-gray-500">Cidade</label>
                            <input type="text" wire:model="city" data-field="city" class="{{ $fieldClass }}">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">UF</label>
                            <input type="text" wire:model="state" data-field="state" maxlength="2" class="uppercase {{ $fieldClass }}">
                        </div>
                    </div>
                @endif

                {{-- Passo 3: bairro --}}
                @if (! $this->addressIsRestricted || filled($city))
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">Bairro</label>
                        @if ($this->addressIsRestricted)
                            <x-menu.combobox
                                wire:key="checkout-neighborhood-{{ $cityId }}"
                                data-field="neighborhood"
                                name="neighborhood"
                                :options="$this->neighborhoodOptions"
                                placeholder="Escolha o bairro…"
                                search-placeholder="Buscar bairro…"
                                empty-text="Bairro não encontrado no catálogo desta cidade."
                                :disabled="blank($cityId)" />
                        @else
                            <input type="text" wire:model.live.debounce.500ms="neighborhood" data-field="neighborhood" class="{{ $fieldClass }}">
                        @endif
                        @error('neighborhood') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Passo 4: rua, número, complemento --}}
                @if (! $this->addressIsRestricted || filled($street) || filled($neighborhood))
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2">
                            <label class="mb-1 block text-xs text-gray-500">Rua</label>
                            <input type="text" wire:model="street" data-field="street" class="{{ $fieldClass }}">
                            @error('street') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">Número</label>
                            <input type="text" wire:model="number" placeholder="Nº ou S/N" class="{{ $fieldClass }}">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-gray-500">
                            Complemento
                            @if (blank($number))
                                <span class="text-yellow-400">(obrigatório sem número)</span>
                            @endif
                        </label>
                        <input type="text" wire:model="complement" data-field="complement"
                               placeholder="Ponto de referência"
                               class="{{ $fieldClass }}">
                        @error('complement') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
            @endif

            @if ($errorMessage && $errorSection === 'delivery')
                <div class="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{{ $errorMessage }}</div>
            @endif

            @if ($this->deliveryFeeResolution && $this->deliveryFeeResolution['blocked'])
                <div class="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">
                    {{ $this->deliveryFeeResolution['message'] }}
                </div>
            @elseif ($this->deliveryFeeResolution && $this->deliveryFeeResolution['is_unlisted_neighborhood'])
                <div class="rounded-lg border border-yellow-500/40 bg-yellow-500/10 px-3 py-2 text-xs text-yellow-400">
                    <p>Seu bairro está fora da nossa área usual de entrega. Vamos confirmar a entrega com você após o pedido.</p>
                    <dl class="mt-2 space-y-0.5 border-t border-yellow-500/20 pt-2">
                        <div class="flex justify-between">
                            <dt>Taxa de entrega</dt>
                            <dd>R$ {{ number_format($this->deliveryFeeResolution['base_fee'], 2, ',', '.') }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>+ Taxa de área não mapeada</dt>
                            <dd>R$ {{ number_format($this->deliveryFeeResolution['unlisted_surcharge'], 2, ',', '.') }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-yellow-500/20 pt-0.5 font-bold">
                            <dt>Total da entrega</dt>
                            <dd>R$ {{ number_format($this->deliveryFeeResolution['fee'], 2, ',', '.') }}</dd>
                        </div>
                    </dl>
                </div>
            @endif
        </div>
    @endif

    {{-- 4. Forma de pagamento --}}
    <div class="rounded-xl border border-white/10 bg-gray-900 p-4">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
                <x-heroicon-o-credit-card class="h-4 w-4" />
                Forma(s) de pagamento
            </h2>
            <button type="button" wire:click="addPaymentLine" class="flex cursor-pointer items-center gap-1 text-xs font-semibold text-[var(--tenant-primary)]">
                <x-heroicon-o-plus class="h-3.5 w-3.5" /> Adicionar
            </button>
        </div>

        @if ($errorMessage && $errorSection === 'payment')
            <div class="mb-2 rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{{ $errorMessage }}</div>
        @endif

        <div class="space-y-2">
            @foreach ($payments as $index => $line)
                <div wire:key="checkout-payment-{{ $index }}" class="rounded-lg border border-gray-700 bg-gray-800 p-2.5">
                    <div class="flex items-center gap-2">
                        <select wire:change="selectPaymentOptionForLine({{ $index }}, $event.target.value)"
                                class="flex-1 rounded-lg border border-gray-700 bg-gray-900 px-2 py-2 text-sm text-white focus:border-[var(--tenant-primary)] focus:outline-none">
                            <option value="" @selected(blank($line['payment_option_id']))>Escolha...</option>
                            @foreach ($this->paymentOptions as $option)
                                <option value="{{ $option->id }}" @selected($line['payment_option_id'] == $option->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>

                        <div class="relative w-28 shrink-0">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500">R$</span>
                            <input type="text" inputmode="numeric"
                                   wire:model.live.debounce.500ms="payments.{{ $index }}.amount"
                                   x-on:input="$el.value = window.maskMoney($el.value)"
                                   placeholder="0,00"
                                   class="w-full rounded-lg border border-gray-700 bg-gray-900 py-2 pl-7 pr-2 text-right text-sm text-white focus:border-[var(--tenant-primary)] focus:outline-none">
                        </div>

                        @if (count($payments) > 1)
                            <button type="button" wire:click="removePaymentLine({{ $index }})" class="cursor-pointer text-gray-500 hover:text-red-400">
                                <x-heroicon-o-trash class="h-4 w-4" />
                            </button>
                        @endif
                    </div>

                    @if ($this->isLineCash($index))
                        <div class="relative mt-2">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500">R$</span>
                            <input type="text" inputmode="numeric"
                                   wire:model.live.debounce.500ms="payments.{{ $index }}.change_for"
                                   x-on:input="$el.value = window.maskMoney($el.value)"
                                   placeholder="Troco para (deixe em branco se não precisar)"
                                   class="w-full rounded-lg border border-gray-700 bg-gray-900 py-2 pl-7 pr-2 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none">
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- 5. Observação --}}
    <div class="rounded-xl border border-white/10 bg-gray-900 p-4">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
            <x-heroicon-o-chat-bubble-left-ellipsis class="h-4 w-4" />
            Observação (opcional)
        </h2>
        <textarea wire:model.live.debounce.500ms="notes" rows="2"
                  placeholder="Ex.: sem cebola, entregar na portaria..."
                  class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none"></textarea>
    </div>

    {{-- 6. Itens do pedido com valores totais --}}
    <div class="rounded-xl border border-white/10 bg-gray-900 p-4">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-gray-400">
            <x-heroicon-o-shopping-cart class="h-4 w-4" />
            Seu pedido
        </h2>

        @if ($errorMessage && $errorSection === 'items')
            <div class="mb-2 rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{{ $errorMessage }}</div>
        @endif

        @forelse ($this->cartLines as $line)
            <div wire:key="checkout-line-{{ $line['index'] }}" class="flex gap-3 border-b border-white/5 py-2 last:border-b-0">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/5">
                    @if ($line['image_url'])
                        <img src="{{ $line['image_url'] }}" alt="" class="h-full w-full object-cover">
                    @else
                        <x-heroicon-o-photo class="h-5 w-5 text-gray-600" />
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex justify-between text-sm text-gray-200">
                        <span class="min-w-0 truncate pr-2">
                            {{ $line['quantity'] }}x
                            @if ($line['category_name'])
                                <span class="mx-1 rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-400">{{ $line['category_name'] }}</span>
                            @endif
                            {{ $line['name'] }}
                        </span>
                        <span class="shrink-0 font-medium">R$ {{ number_format($line['line_total'], 2, ',', '.') }}</span>
                    </div>
                    @foreach ($line['addons_display'] as $addonLine)
                        <p class="text-xs text-gray-500">+ {{ $addonLine }}</p>
                    @endforeach
                    @foreach ($line['gifts_display'] ?? [] as $giftLine)
                        <p class="text-xs font-semibold text-emerald-600">{{ $giftLine }}</p>
                    @endforeach
                    @if ($line['note'])
                        <p class="text-xs italic text-gray-500">"{{ $line['note'] }}"</p>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Carrinho vazio.</p>
        @endforelse

        <dl class="mt-3 space-y-1 border-t border-white/10 pt-3 text-sm">
            <div class="flex justify-between text-gray-400">
                <dt>Subtotal</dt>
                <dd>R$ {{ number_format($this->itemsTotal, 2, ',', '.') }}</dd>
            </div>
            <div class="flex justify-between text-gray-400">
                <dt>Entrega</dt>
                <dd>R$ {{ number_format($this->deliveryFee, 2, ',', '.') }}</dd>
            </div>
            <div class="flex justify-between text-base font-bold text-white">
                <dt>Total</dt>
                <dd class="text-green-400">R$ {{ number_format($this->grandTotal, 2, ',', '.') }}</dd>
            </div>
        </dl>
    </div>

    @if ($errorMessage && $errorSection === null)
        <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-400">{{ $errorMessage }}</div>
    @endif

    <button type="button"
            x-data
            @if ($this->recaptchaEnabled)
                x-init="
                    if (! window.__rfRecaptchaLoaded) {
                        window.__rfRecaptchaLoaded = true;
                        const s = document.createElement('script');
                        s.src = 'https://www.google.com/recaptcha/api.js?render={{ $this->recaptchaSiteKey }}';
                        document.head.appendChild(s);
                    }
                "
                @click="
                    grecaptcha.ready(() => {
                        grecaptcha.execute('{{ $this->recaptchaSiteKey }}', { action: 'checkout' }).then((token) => {
                            $wire.recaptchaToken = token;
                            $wire.submit();
                        });
                    });
                "
            @else
                wire:click="submit"
            @endif
            class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-[var(--tenant-primary)] py-4 text-sm font-bold uppercase tracking-wide text-white shadow-xl transition active:scale-[0.98]">
        <x-heroicon-o-paper-airplane class="h-5 w-5" /> Confirmar pedido e enviar pro WhatsApp
    </button>

    @if ($this->recaptchaEnabled)
        <p class="text-center text-[10px] leading-tight text-gray-500">
            Protegido por reCAPTCHA — aplicam-se a
            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener" class="underline">Política de Privacidade</a>
            e os
            <a href="https://policies.google.com/terms" target="_blank" rel="noopener" class="underline">Termos de Serviço</a>
            do Google.
        </p>
    @endif

</div>

@script
<script>
    // Máscaras puramente visuais — o servidor sempre normaliza (remove
    // pontuação) antes de salvar/comparar, então não afetam a validação.
    window.maskCep = (value) => value.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');

    window.maskCpf = (value) => value.replace(/\D/g, '').slice(0, 11)
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');

    window.maskPhone = (value) => {
        value = value.replace(/\D/g, '').slice(0, 11);

        if (value.length > 10) {
            return value.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
        }
        if (value.length > 6) {
            return value.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
        }
        if (value.length > 2) {
            return value.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
        }
        if (value.length > 0) {
            return value.replace(/^(\d*)/, '($1');
        }

        return value;
    };

    // Máscara monetária (centavos): digita "1500" e o campo mostra "15,00",
    // digita "150000" e mostra "1.500,00" — mesmo padrão do modal de troco
    // do Pizzaria-App.
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

    // Rola a página até o primeiro campo obrigatório com erro e foca nele,
    // em vez de deixar o usuário procurar o campo sozinho.
    $wire.on('checkout-validation-failed', ({ field }) => {
        const el = document.querySelector(`[data-field="${field}"]`);

        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.focus({ preventScroll: true });
        }
    });
</script>
@endscript

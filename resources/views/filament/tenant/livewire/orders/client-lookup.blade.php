<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cliente</h3>
        <button type="button" wire:click="openSearchModal"
            class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
            Buscar cliente
        </button>
    </div>

    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        <input type="checkbox" wire:click="toggleWithoutClient" @checked($withoutClient)
            class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500">
        Pedido sem cliente cadastrado
    </label>

    <div class="space-y-2 {{ $withoutClient ? 'opacity-40 pointer-events-none' : '' }}">
        <div class="relative">
            <input type="tel" inputmode="numeric" maxlength="15" wire:model.live.debounce.500ms="phone" placeholder="(11) 99999-9999"
                x-init="$nextTick(() => $el.value = window.maskPhone($el.value))"
                x-on:input="$el.value = window.maskPhone($el.value)"
                class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500">
            @if ($clientFound)
                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-emerald-500" title="Cliente encontrado">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                </span>
            @endif
        </div>

        <input type="text" wire:model.live.debounce.500ms="name" placeholder="Nome do cliente"
            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500">

        {{-- Endereço: só aparece quando a opção de entrega escolhida exige (a AttendOrder passa
             `requiresAddress` a cada render). --}}
        @if ($requiresAddress)
            <div x-data="{ open: true }">
                <button type="button" x-on:click="open = !open"
                    class="text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <span x-show="!open">+ Endereço de entrega</span>
                    <span x-show="open">- Endereço de entrega</span>
                </button>

                <div x-show="open" x-cloak class="mt-2 grid grid-cols-6 gap-2">
                    <div class="col-span-2">
                        <input type="text" inputmode="numeric" maxlength="9" wire:model="zipCode" wire:blur="lookupCep" placeholder="CEP"
                            x-init="$nextTick(() => $el.value = window.maskCep($el.value))"
                            x-on:input="$el.value = window.maskCep($el.value)"
                            class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        @if ($cepNotFound)
                            <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-0.5">CEP não encontrado — preencha manualmente.</p>
                        @endif
                    </div>
                    <div class="col-span-4">
                        <input type="text" wire:model.live.debounce.500ms="street" placeholder="Rua"
                            class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div class="col-span-2">
                        <input type="text" wire:model.live.debounce.500ms="number" placeholder="Número"
                            class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div class="col-span-4">
                        <input type="text" wire:model.live.debounce.500ms="complement" placeholder="Complemento / ponto de referência"
                            class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div class="col-span-3">
                        <input type="text" wire:model.live.debounce.500ms="neighborhood" placeholder="Bairro"
                            class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                    <div class="col-span-3">
                        <input type="text" wire:model.live.debounce.500ms="city" placeholder="Cidade"
                            class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($searchModalOpen)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/70 backdrop-blur-sm p-4"
            x-trap.noscroll="true" wire:click.self="closeSearchModal">
            <div class="w-full sm:max-w-md bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-2xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Buscar cliente</h4>
                    <button type="button" wire:click="closeSearchModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="Nome ou telefone..."
                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">

                <div class="max-h-64 overflow-y-auto divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->searchResults as $result)
                        <button type="button" wire:click="selectClient({{ $result->id }})"
                            class="w-full text-left px-2 py-2 hover:bg-gray-50 dark:hover:bg-white/5 rounded-lg">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $result->name }}</p>
                            <p class="text-xs text-gray-400">{{ $result->phone ?? 'sem telefone' }}</p>
                        </button>
                    @empty
                        <p class="text-xs text-gray-400 py-4 text-center">Nenhum resultado.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>

@script
<script>
    // Máscaras puramente visuais — o servidor sempre normaliza (remove pontuação) antes de buscar/salvar.
    window.maskCep = (value) => value.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');

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
</script>
@endscript

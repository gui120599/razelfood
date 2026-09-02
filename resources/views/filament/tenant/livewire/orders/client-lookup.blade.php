<div class="space-y-3" x-data="{ open: false }">
    <label class="flex items-center gap-2 text-sm">
        <input
            type="checkbox"
            wire:click="toggleWithoutClient"
            @checked($withoutClient)
            class="h-4 w-4 rounded border-gray-300 text-rf-orange-600 focus:ring-rf-orange-600 dark:border-white/20 dark:bg-white/5"
        >
        <span class="font-medium text-gray-700 dark:text-gray-300">Pedido sem cliente cadastrado</span>
    </label>

    @unless ($withoutClient)
        <button type="button" x-on:click="open = ! open" class="flex w-full items-center justify-between pt-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Dados do cliente</span>
            <x-heroicon-o-chevron-down x-bind:class="open ? 'rotate-180' : ''" class="h-4 w-4 text-gray-400 transition-transform dark:text-gray-500" />
        </button>

        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="space-y-3"
        >
        <div class="flex items-center justify-between">
            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Telefone</label>
            <button type="button" wire:click="openSearchModal" class="flex items-center gap-1 text-xs font-semibold text-rf-orange-600 hover:text-rf-orange-700">
                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                Buscar cliente
            </button>
        </div>
        <div class="relative -mt-2">
            <input
                type="tel"
                inputmode="numeric"
                maxlength="15"
                wire:model.live.debounce.500ms="phone"
                x-init="$nextTick(() => $el.value = window.maskPhone($el.value))"
                x-on:input="$el.value = window.maskPhone($el.value)"
                placeholder="(11) 99999-9999"
                class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
            >
            @if ($clientFound)
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-rf-teal-500 dark:text-rf-teal-300" title="Cliente encontrado">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                </span>
            @endif
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Nome</label>
            <input
                type="text"
                wire:model.live.debounce.500ms="name"
                placeholder="Nome do cliente"
                class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
            >
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div class="col-span-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">CEP</label>
                <input
                    type="text"
                    inputmode="numeric"
                    maxlength="9"
                    wire:model="zipCode"
                    wire:blur="lookupCep"
                    x-init="$nextTick(() => $el.value = window.maskCep($el.value))"
                    x-on:input="$el.value = window.maskCep($el.value)"
                    placeholder="00000-000"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                >
                @if ($cepNotFound)
                    <p class="mt-1 text-[11px] text-rf-amber-300">CEP não encontrado — preencha manualmente.</p>
                @endif
            </div>

            <div class="col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Rua</label>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="street"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                >
            </div>
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Número</label>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="number"
                    placeholder="S/N"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                >
            </div>

            <div class="col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Complemento</label>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="complement"
                    placeholder="Ponto de referência"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                >
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Bairro</label>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="neighborhood"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                >
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cidade</label>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="city"
                    class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                >
            </div>
        </div>
        </div>
    @endunless

    @if ($searchModalOpen)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 backdrop-blur-sm sm:items-center" x-data>
            <div class="flex max-h-[80vh] w-full flex-col overflow-hidden rounded-t-2xl border border-gray-200 bg-white sm:max-w-md sm:rounded-2xl dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="font-heading text-base font-semibold text-gray-900 dark:text-white">Buscar cliente</h3>
                    <button type="button" wire:click="closeSearchModal" class="text-gray-400 hover:text-gray-700 dark:hover:text-white">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="px-4 py-3">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="searchQuery"
                        placeholder="Nome ou telefone..."
                        autofocus
                        class="fi-input block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-2 focus:ring-rf-orange-600 dark:border-none dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                    >
                </div>

                <div class="flex-1 overflow-y-auto px-4 pb-4">
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($this->searchResults as $result)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectClient({{ $result->id }})"
                                    class="flex w-full flex-col items-start gap-0.5 py-2.5 text-left"
                                >
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $result->name }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $result->phone }}</span>
                                </button>
                            </li>
                        @empty
                            <li class="py-6 text-center text-sm text-gray-500 dark:text-gray-500">
                                {{ $searchQuery === '' ? 'Digite pra buscar.' : 'Nenhum cliente encontrado.' }}
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    @endif
</div>

@script
<script>
    // Máscaras puramente visuais — o servidor sempre normaliza (remove
    // pontuação) antes de buscar/salvar. Mesmas usadas no checkout público.
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

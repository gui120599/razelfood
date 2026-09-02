@props([
    'name',
    'label' => null,
    'options' => [],
    'placeholder' => 'Selecione…',
    'searchPlaceholder' => 'Buscar…',
    'emptyText' => 'Nada encontrado.',
    'disabled' => false,
])

{{--
    Select com busca no estilo do combobox do Filament, para o cardápio/checkout
    público (tema escuro, fora do Filament). As opções são pré-renderizadas do
    servidor e filtradas no cliente — use um `wire:key` que mude quando a lista
    de opções mudar (ex.: `wire:key="cidade-{{ $state }}"`) para o Alpine
    reinicializar com as opções novas.
--}}
<div
    x-data="{
        selected: $wire.entangle('{{ $name }}').live,
        open: false,
        search: '',
        options: @js($options),
        get selectedLabel() { return this.options[this.selected] ?? '' },
        get results() {
            const term = this.search.toLowerCase().trim();
            return Object.entries(this.options).filter(([, label]) => label.toLowerCase().includes(term));
        },
        isSelected(value) { return (this.selected ?? '').toString() === value.toString() },
        choose(value) { this.selected = value; this.open = false; this.search = ''; },
        toggle() { this.open = ! this.open; if (this.open) this.$nextTick(() => this.$refs.search?.focus()); },
    }"
    @keydown.escape="open = false"
    @click.outside="open = false"
    {{ $attributes->class(['relative']) }}
>
    @if ($label)
        <label class="mb-1 block text-xs text-gray-500">{{ $label }}</label>
    @endif

    <button type="button" @disabled($disabled) @click="toggle()"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-left text-sm text-white focus:border-[var(--tenant-primary)] focus:outline-none disabled:cursor-not-allowed disabled:opacity-50">
        <span class="truncate" :class="selectedLabel ? 'text-white' : 'text-gray-500'"
              x-text="selectedLabel || @js($placeholder)"></span>
        <x-heroicon-o-chevron-up-down class="h-4 w-4 shrink-0 text-gray-500" />
    </button>

    <div x-show="open" x-cloak x-transition
         class="absolute left-0 right-0 z-30 mt-1 overflow-hidden rounded-lg border border-gray-700 bg-gray-900 shadow-2xl">
        <div class="border-b border-gray-800 p-2">
            <input x-ref="search" x-model="search" type="text" placeholder="{{ $searchPlaceholder }}"
                   class="w-full rounded-md border border-gray-700 bg-gray-800 px-2.5 py-1.5 text-sm text-white placeholder-gray-600 focus:border-[var(--tenant-primary)] focus:outline-none">
        </div>
        <ul class="max-h-56 overflow-y-auto py-1">
            <template x-for="[value, label] in results" :key="value">
                <li>
                    <button type="button" @click="choose(value)"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-white/5"
                            :class="isSelected(value) ? 'text-[var(--tenant-primary)]' : 'text-gray-200'">
                        <span class="truncate" x-text="label"></span>
                        <x-heroicon-o-check class="h-4 w-4 shrink-0" x-show="isSelected(value)" />
                    </button>
                </li>
            </template>
            <li x-show="results.length === 0" class="px-3 py-2 text-sm text-gray-500">{{ $emptyText }}</li>
        </ul>
    </div>
</div>

<div class="space-y-4 text-center" x-data="{ copied: false }">
    <img src="{{ $qrDataUri }}" alt="QR code do link de entrega" class="mx-auto h-48 w-48">

    <div class="flex items-center gap-2">
        <input
            type="text"
            readonly
            value="{{ $url }}"
            class="fi-input flex-1 rounded-lg border-none bg-gray-100 px-3 py-2 text-xs dark:bg-gray-800"
            x-ref="linkInput"
            onclick="this.select()"
        >
        <button
            type="button"
            x-on:click="navigator.clipboard.writeText($refs.linkInput.value); copied = true; setTimeout(() => copied = false, 2000)"
            class="fi-btn fi-btn-color-gray shrink-0 rounded-lg border px-3 py-2 text-xs font-medium dark:border-gray-600"
        >
            <span x-show="!copied">Copiar</span>
            <span x-show="copied" x-cloak>Copiado!</span>
        </button>
    </div>

    <a
        href="{{ $whatsappUrl }}"
        target="_blank"
        rel="noopener"
        class="fi-btn fi-btn-color-success inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white"
    >
        Enviar por WhatsApp
    </a>

    <p class="text-xs text-gray-500 dark:text-gray-400">Válido por 12 horas. Envie esse link ou o QR code para quem for fazer a entrega — não precisa de login.</p>
</div>

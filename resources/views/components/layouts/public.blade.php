<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->name ?? config('app.name') }}</title>

    @php($faviconUrl = ($tenant->favicon_path ?? null)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->favicon_path)
        : asset('images/brand/razelfood-icon-32.png'))
    <link rel="icon" type="image/png" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">

    <style>
        {!! \App\Support\TenantColorScale::cssVariables($tenant->primary_color ?? null) !!}

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111827; }
        ::-webkit-scrollbar-thumb { background: #374151; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #4b5563; }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-black text-gray-100 antialiased">
    {{-- Marca d'água: identidade do restaurante ao fundo (docs/identidade-visual-design-system.md §5).
         Altura configurável por tenant (tenants.watermark_height); a largura acompanha a proporção
         natural da logo e pode ultrapassar a coluna estreita de listagem de produtos — de propósito,
         por isso o wrapper não tem max-w-lg/mx-auto, só o conteúdo em si tem. --}}
    @if ($tenant->logo_path ?? null)
        <div class="pointer-events-none fixed inset-0 z-0 flex items-center justify-center overflow-hidden">
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path) }}" alt=""
                 class="w-auto max-w-none object-contain opacity-5 grayscale"
                 style="height: {{ $tenant->watermark_height ?? 288 }}px">
        </div>
    @endif

    <div class="relative z-10 mx-auto flex h-full w-full max-w-lg flex-col">
        <header class="sticky top-0 z-30 border-b border-white/10 bg-black/95 backdrop-blur">
            <div class="flex items-center gap-3 px-4 py-3">
                @if ($tenant->logo_path ?? null)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path) }}"
                         alt="{{ $tenant->name }}" class="h-10 w-10 shrink-0 rounded-full object-cover bg-white">
                @endif
                <h1 class="min-w-0 truncate text-lg font-bold text-white">{{ $tenant->name ?? '' }}</h1>

                {{-- Acesso ao painel do tenant (mesmo subdomínio, path /painel).
                     Filament redireciona para o login se não houver sessão. --}}
                <a href="{{ url('/painel') }}"
                   class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-200 transition hover:bg-white/10">
                    <x-heroicon-o-lock-closed class="h-4 w-4" />
                    Painel
                </a>
            </div>
        </header>

        <main class="flex-1 px-2 py-3">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>

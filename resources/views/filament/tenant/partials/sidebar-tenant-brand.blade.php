@php
    use App\Filament\Tenant\Pages\EstablishmentSettings;

    $tenant = \App\Support\CurrentTenant::get();
    $isCollapsible = filament()->isSidebarCollapsibleOnDesktop() || filament()->isSidebarFullyCollapsibleOnDesktop();
    $canManageSettings = EstablishmentSettings::canAccess();
    $settingsUrl = $canManageSettings ? EstablishmentSettings::getUrl(panel: 'tenant') : null;
    $brandTag = $canManageSettings ? 'a' : 'div';
@endphp

@if ($tenant)
    <{{ $brandTag }}
        @if ($isCollapsible) x-show="$store.sidebar.isOpen" @endif
        @if ($settingsUrl) href="{{ $settingsUrl }}" @endif
        @class([
            'fi-tenant-brand mx-3 mb-3 flex items-center gap-3 rounded-lg border px-3 py-2.5',
            'border-gray-950/10 bg-gray-950/5 dark:border-white/10 dark:bg-white/5',
            'transition hover:bg-gray-950/10 dark:hover:bg-white/10' => $canManageSettings,
        ])
    >
        @if ($tenant->logo_path)
            <img
                src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path) }}"
                alt="{{ $tenant->name }}"
                class="h-8 w-8 shrink-0 rounded-full bg-white object-cover"
            >
        @else
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-950/10 text-sm font-semibold uppercase text-gray-700 dark:bg-white/10 dark:text-white">
                {{ Str::substr($tenant->name, 0, 1) }}
            </span>
        @endif

        <span class="truncate font-heading text-sm font-semibold text-gray-700 dark:text-white" title="{{ $tenant->name }}">
            {{ $tenant->name }}
        </span>
    </{{ $brandTag }}>
@endif

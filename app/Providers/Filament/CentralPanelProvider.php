<?php

namespace App\Providers\Filament;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class CentralPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('central')
            ->domain('interno.'.config('tenancy.base_domain'))
            ->path('central')
            ->viteTheme('resources/css/filament/central/theme.css')
            ->login()
            ->colors([
                'primary' => Color::hex('#FA6400'),
                'info' => Color::hex('#007896'),
                'success' => Color::hex('#16A34A'),
                'warning' => Color::hex('#F59E0B'),
                'danger' => Color::hex('#DC2626'),
            ])
            ->defaultThemeMode(ThemeMode::Dark)
            ->topbar(false)
            ->sidebarCollapsibleOnDesktop()
            ->font('Inter')
            ->brandName('RazelFood')
            ->brandLogo(new HtmlString(
                '<span class="rf-brand-logo-chip"><img src="'.asset('images/brand/razelfood-lockup.png').'" alt="RazelFood" style="height: 1.5rem; width: auto;"></span>'
            ))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/brand/razelfood-icon-32.png'))
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

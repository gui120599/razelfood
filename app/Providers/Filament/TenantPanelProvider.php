<?php

namespace App\Providers\Filament;

use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Widgets\AccountWidget;
use App\Http\Middleware\ApplyTenantScopes;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('painel')
            // Tenancy por path: razelfood.com.br/painel/{slug}. O {tenant} da
            // URL é resolvido por route-model binding contra `tenants.slug`;
            // ApplyTenantScopes (tenantMiddleware persistente) faz a ponte com
            // CurrentTenant/TenantScope/spatie-teams. tenantMenu(false): cada
            // usuário pertence a no máximo um tenant, não há o que trocar.
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantMiddleware([
                ApplyTenantScopes::class,
            ], isPersistent: true)
            ->tenantMenu(false)
            ->viteTheme('resources/css/filament/tenant/theme.css')
            // Login em /painel/login (fora do contexto de tenant). O isolamento
            // no login vem de: email globalmente único (1 user = 1 tenant),
            // canAccessPanel('tenant') (tenant_id != null) e canAccessTenant()
            // ao resolver o {slug} da URL de destino. Não precisa mais da classe
            // Login custom que forçava a credencial tenant_id (era p/ subdomínio).
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
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => view('filament.tenant.partials.sidebar-tenant-brand')->render(),
            )
            // discoverResources() precisa rodar ANTES de ->plugin(FilamentShieldPlugin::make()):
            // HasPlugins::plugin() chama $plugin->register($panel) na hora, e o
            // FilamentShieldPlugin só registra o RoleResource dele próprio se
            // Utils::isResourcePublished($panel) não achar nenhum "...\RoleResource"
            // já presente em $panel->getResources() — precisa que o RoleResource
            // customizado do projeto (que corrige o isolamento por tenant do Shield,
            // ver app/Filament/Tenant/Resources/Roles/RoleResource.php) já tenha sido
            // descoberto nesse momento, senão o Shield registra o dele também e
            // "Funções" aparece duplicado na navegação.
            ->discoverResources(in: app_path('Filament/Tenant/Resources'), for: 'App\Filament\Tenant\Resources')
            ->plugin(FilamentShieldPlugin::make())
            ->discoverPages(in: app_path('Filament/Tenant/Pages'), for: 'App\Filament\Tenant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Tenant/Widgets'), for: 'App\Filament\Tenant\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->navigationItems([
                NavigationItem::make('Ver cardápio')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->url(fn (): string => route('menu.index', ['tenant' => CurrentTenant::get()?->slug]), shouldOpenInNewTab: true)
                    ->sort(99),
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

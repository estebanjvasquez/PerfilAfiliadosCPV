<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Login;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use App\Filament\Pages\Register;
use App\Filament\Resources\EmpresaResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Reemplaza config/filament.php (estilo v2, eliminado en v3 a favor de un
 * Panel registrado por codigo). Traduce 1 a 1 lo que tenia esa config:
 * path 'admin', guard 'web', pagina de login propia (con Turnstile),
 * paths de discovery de Resources/Pages/Widgets, el widget de completitud
 * de EmpresaResource (no cubierto por discoverWidgets porque vive anidado
 * dentro de la carpeta del Resource, no en Filament/Widgets), dark mode,
 * favicon, fuente de Google, y el middleware de EnsureEmailIsVerified
 * (User implementa MustVerifyEmail).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(env('FILAMENT_PATH', 'admin'))
            ->domain(env('FILAMENT_DOMAIN'))
            ->authGuard(env('FILAMENT_AUTH_GUARD', 'web'))
            ->login(Login::class)
            ->registration(Register::class)
            ->homeUrl('/')
            ->brandName(config('app.name'))
            ->favicon(asset('images/capet.jpg'))
            ->darkMode()
            ->font('DM Sans')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
                EmpresaResource\Widgets\StatsOverview::class,
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
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureEmailIsVerified::class,
            ])
            ->plugin(
                // Breezy v2 dejo de manejar login/registro (ahora es 100% nativo
                // de Filament, ver ->login()/->registration() arriba) - lo unico
                // que sigue aportando es la pagina "My Profile", que es lo unico
                // que se registra aca (traduccion 1 a 1 de la vieja
                // config/filament-breezy.php: enable_profile_page=true,
                // show_profile_page_in_user_menu=true,
                // show_profile_page_in_navbar=false, password_rules=['min:8']).
                BreezyCore::make()
                    ->myProfile(
                        shouldRegisterUserMenu: true,
                        shouldRegisterNavigation: false,
                    )
                    ->passwordUpdateRules(rules: ['min:8'], requiresCurrentPassword: true)
            );
    }
}

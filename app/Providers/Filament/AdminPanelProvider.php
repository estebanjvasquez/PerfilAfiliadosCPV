<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Login;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use App\Filament\Pages\Register;
use App\Filament\Resources\EmpresaResource;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Tables\Table;
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
    /**
     * Fase F del upgrade: aplica "esto en todo" (pedido del cliente) sin tocar los ~20
     * archivos de Resources/RelationManagers/reportes uno por uno. Table::configureUsing()
     * es un hook global de Filament (Table::make() llama a configure() -> setUp() en TODA
     * tabla que se construya en el panel, ver Filament\Support\Concerns\Configurable) - un
     * unico ->deferLoading() aca cubre todas las tablas existentes y las que se agreguen
     * despues, sin necesidad de repetirlo en cada table().
     *
     * deferLoading() hace que la tabla renderice su carcasa (header, filtros, paginacion)
     * de inmediato y dispare la consulta real en un segundo request via wire:init="loadTable"
     * (ver vendor/filament/tables/resources/views/index.blade.php) - la fila de "streaming"
     * pedida: la pagina no espera a la consulta para mostrarse, se ve un skeleton
     * (animate-pulse) mientras carga.
     *
     * Los widgets (StatsOverview, los de GerenciaDashboard) no necesitan nada aca: son lazy
     * por defecto en Filament v3 (Filament\Support\Concerns\CanBeLazy::$isLazy = true),
     * ningun widget de esta app lo desactiva.
     */
    public function boot(): void
    {
        Table::configureUsing(fn (Table $table) => $table->deferLoading());

        // Ver public/css/filament/custom/panel.css: fix puntual del Select de una sola opcion
        // con etiquetas largas (Ciudad) que se rompe visualmente en mobile.
        //
        // Cache-busting: Asset::getVersion() (vendor/filament/support/src/Assets/Asset.php) usa
        // FilamentAsset::getAppVersion() para el "?v=" del link cuando el asset es del package
        // 'app' (el default de Css::make(), ver Css::getRelativePublicPath()) - sin esto, cae al
        // fallback de la version de filament/support, que NO cambia entre nuestros propios
        // despliegues. Encontrado en vivo: un fix a este mismo archivo quedo confirmado en el
        // servidor (curl trae el contenido nuevo) pero Cloudflare seguia sirviendo la version
        // vieja desde cache (cf-cache-status: HIT, Cache-Control: max-age=14400 = 4h) porque la
        // URL nunca cambiaba. Atar la version al filemtime() del propio archivo hace que la URL
        // cambie automaticamente cada vez que este CSS se edita y se despliega (git deja el mtime
        // en el momento del `git reset --hard` del deploy), forzando a Cloudflare/navegador a
        // pedir el archivo de nuevo en vez de reusar el cache viejo.
        FilamentAsset::appVersion((string) filemtime(public_path('css/filament/custom/panel.css')));

        FilamentAsset::register([
            Css::make('panel-custom', public_path('css/filament/custom/panel.css')),
        ]);
    }

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
            // Fase F del upgrade: navegacion tipo SPA con Livewire wire:navigate (Filament
            // renderiza cada link interno del panel con wire:navigate cuando esto esta activo -
            // ver Filament\Support\generate_href_html()). Elimina el full-page-reload entre
            // paginas del panel (assets/head ya cargados no se vuelven a pedir) y precarga la
            // pagina de destino al presionar el link (antes de soltar el click), no solo al
            // completarse la navegacion. El widget de Turnstile (wire:ignore) y el link de
            // descarga de PDF (target=_blank, ver generate_href_html()) no se ven afectados.
            ->spa()
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

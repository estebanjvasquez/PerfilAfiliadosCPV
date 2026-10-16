<?php

/**
 * Prueba de humo AUTENTICADA y de SOLO LECTURA de Inicio en staging (CPV-HOME-BRANDING-AND-LAYOUT,
 * instrucción `6210321522`). Amplía smoke_home_help_readonly.php con el logo en el h1, «Inicio» y el
 * orden de las tarjetas. Se ejecuta DENTRO del contenedor de la app, como www-data, leído por stdin.
 * Mismo patrón que los otros smokes de docs/deploy: usuarios EN MEMORIA, sesión y caché en memoria,
 * Mail en fake y una transacción pgsql READ ONLY que termina en ROLLBACK. Solo GET.
 */

$stdout = fopen('php://stdout', 'w');
$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks) {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->instance('request', Illuminate\Http\Request::create('https://pruebas.camarapetrolera.app/', 'GET'));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    fwrite($stdout, json_encode(['checks' => [['check' => 'arranque', 'ok' => false, 'detail' => get_class($e).': '.strtok($e->getMessage(), "\n")]], 'failed' => 1])."\n");
    exit(1);
}

config(['session.driver' => 'array', 'cache.default' => 'array']);
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Notification::fake();

$db = Illuminate\Support\Facades\DB::connection('pgsql');
$opened = false;
$exit = 0;

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');
    $check('transaccion READ ONLY', $db->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on');

    $panel = Filament\Facades\Filament::getPanel('admin');
    $widgets = $panel->getWidgets();
    $check('registro: sin FilamentInfoWidget', ! in_array(Filament\Widgets\FilamentInfoWidget::class, $widgets, true));
    $check('registro: cuenta, ayuda y widget de empresa', in_array(Filament\Widgets\AccountWidget::class, $widgets, true)
        && in_array(App\Filament\Widgets\ProfileHelpWidget::class, $widgets, true)
        && in_array(App\Filament\Resources\EmpresaResource\Widgets\StatsOverview::class, $widgets, true));

    $superAdmin = Spatie\Permission\Models\Role::findByName(config('filament-shield.super_admin.name'), 'web');
    $admin = new App\Models\User();
    $admin->forceFill(['id' => 0, 'name' => 'Smoke Admin (en memoria)', 'email' => 'smoke-admin@invalid.test', 'email_verified_at' => now()]);
    $admin->setRelation('roles', collect([$superAdmin]));
    $admin->setRelation('permissions', collect());
    $comun = new App\Models\User();
    $comun->forceFill(['id' => 0, 'name' => 'Smoke Comun (en memoria)', 'email' => 'smoke-comun@invalid.test', 'email_verified_at' => now()]);
    $comun->setRelation('roles', collect());
    $comun->setRelation('permissions', collect());

    $get = function (string $path, App\Models\User $as) use ($kernel) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        Illuminate\Support\Facades\Auth::guard('web')->setUser($as);

        return $kernel->handle(Illuminate\Http\Request::create('https://pruebas.camarapetrolera.app'.$path, 'GET'));
    };

    $homes = array_values(array_filter($panel->getPages(), fn ($p) => is_a($p, Filament\Pages\Dashboard::class, true)));
    $check('registro: una sola página de inicio, la propia', $homes === [App\Filament\Pages\Dashboard::class], json_encode($homes));
    $check('registro: etiqueta «Inicio»', App\Filament\Pages\Dashboard::getNavigationLabel() === 'Inicio');
    $logo = asset(App\Filament\Pages\Dashboard::LOGO_PATH);
    $size = getimagesize(public_path(App\Filament\Pages\Dashboard::LOGO_PATH)) ?: [0, 0];
    $check('logo: archivo de 400x206 en la imagen', $size[0] === 400 && $size[1] === 206, "{$size[0]}x{$size[1]}");

    $home = parse_url(App\Filament\Pages\Dashboard::getUrl(), PHP_URL_PATH);
    $check('URL de inicio sin cambios', $home === '/admin', (string) $home);
    $manual = App\Filament\Pages\UserManual::getUrl();
    $map = App\Filament\Pages\TaxonomyMapPage::getUrl();

    foreach (['afiliado' => $comun, 'admin' => $admin] as $quien => $as) {
        $r = $get($home, $as);
        $html = (string) $r->getContent();
        $check("{$quien}: Inicio 200", $r->getStatusCode() === 200, 'status='.$r->getStatusCode());
        $check("{$quien}: título Inicio", (bool) preg_match('#<title>\s*Inicio\b#', $html));
        $check("{$quien}: el h1 es el logo con alt", str_contains($html, '<span class="cpv-home-logo"><img src="'.$logo.'" alt="Perfil de Afiliados"'));
        $check("{$quien}: sin «Escritorio»", ! str_contains($html, 'Escritorio'));
        $styles = strpos($html, '.cpv-home-notice .fi-wi-stats-overview-stat-value');
        $check("{$quien}: estilos de Inicio", $styles !== false && str_contains($html, '.dark .cpv-home-logo'));
        $body = substr($html, (int) strpos($html, '</style>', (int) $styles));
        $a = strpos($body, 'fi-account-widget');
        $h = strpos($body, 'cpv-profile-help-widget');
        $n = strpos($body, 'empresa-resource.widgets.stats-overview');
        $check("{$quien}: orden cuenta, ayuda, avisos", $a !== false && $h !== false && $n !== false && $a < $h && $h < $n);
        $start = strpos($html, 'cpv-profile-help-widget');
        $card = $start === false ? '' : substr($html, $start, (strpos($html, '</section>', $start) ?: strlen($html)) - $start);
        $check("{$quien}: tarjeta «Ayuda para completar su perfil»", str_contains($card, 'Ayuda para completar su perfil'));
        $check("{$quien}: enlace al manual y al mapa", str_contains($card, 'href="'.$manual.'"') && str_contains($card, 'href="'.$map.'"'));
        $check("{$quien}: sin la tarjeta de Filament", ! str_contains($html, 'fi-filament-info-widget') && ! str_contains($html, 'filamentphp.com'));
        $check("{$quien}: tarjeta de cuenta con «Salir»", str_contains($html, 'fi-account-widget') && str_contains($html, $panel->getLogoutUrl()));
        $check("{$quien}: widget de empresa presente (diferido)", str_contains($html, 'stats-overview'));

        foreach (['manual' => $manual, 'mapa' => $map] as $destino => $url) {
            $r = $get(parse_url($url, PHP_URL_PATH), $as);
            $check("{$quien}: destino {$destino} 200", $r->getStatusCode() === 200, 'status='.$r->getStatusCode());
            // Vuelta a Inicio por SPA: desde el destino, el ítem «Inicio» del menú enlaza a la misma URL con wire:navigate.
            if ($destino === 'manual') {
                $back = (bool) preg_match('#<a\s[^>]*href="'.preg_quote(App\Filament\Pages\Dashboard::getUrl(), '#').'"[^>]*wire:navigate[^>]*>.*?Inicio#s', (string) $r->getContent());
                $check("{$quien}: desde el manual, «Inicio» vuelve por SPA (wire:navigate)", $back);
            }
        }
    }

    $section = (string) $get(parse_url($manual, PHP_URL_PATH), $comun)->getContent();
    $start = strpos($section, '<h2 id="escritorio">');
    $section = $start === false ? '' : substr($section, $start, 6000);
    $check('manual: «Inicio y el menú» con logo y orden', str_contains($section, 'Inicio y el menú')
        && str_contains($section, 'Perfil de Afiliados') && str_contains($section, 'a todo el ancho')
        && str_contains($section, 'Ayuda para completar su perfil') && ! str_contains($section, 'Escritorio'));

    $salientes = (fn () => count($this->mailables) + count($this->queuedMailables))->call(Illuminate\Support\Facades\Mail::getFacadeRoot());
    $check('ningún correo', $salientes === 0, "salientes={$salientes}");
} catch (Throwable $e) {
    $exit = 1;
    $check('excepción', false, get_class($e).': '.strtok($e->getMessage(), "\n"));
} finally {
    if ($opened) {
        $db->rollBack();
    }
}

$failed = count(array_filter($checks, fn ($c) => ! $c['ok']));
fwrite($stdout, json_encode(['checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
exit($exit === 0 && $failed === 0 ? 0 : 1);

<?php

/**
 * Prueba de humo AUTENTICADA y de SOLO LECTURA del «Mapa de la taxonomía» en staging
 * (CPV-TAXONOMY-MAP, revisión `6194751263`). Se ejecuta DENTRO del contenedor de la app, como
 * www-data, leído por stdin. Mismo patrón que smoke_manual_readonly.php: usuarios EN MEMORIA (nunca
 * guardados), sesión y caché en memoria, Mail en fake, una transacción pgsql READ ONLY que termina en
 * ROLLBACK. Solo GET por el kernel HTTP. Imprime estados y conteos, nunca nombres de la taxonomía.
 *
 * Variables opcionales: EXPECTED_GROUPS / EXPECTED_FAMILIES / EXPECTED_CATEGORIES (conteos del
 * agregado de solo lectura `taxonomy_map_rules_readonly.php`, tomados antes del despliegue).
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
$fmt = fn ($n) => number_format((int) $n, 0, ',', '.');

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');
    $check('transaccion READ ONLY', $db->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on');

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

    $mapPath = parse_url(App\Filament\Pages\TaxonomyMapPage::getUrl(), PHP_URL_PATH);
    $manualPath = parse_url(App\Filament\Pages\UserManual::getUrl(), PHP_URL_PATH);
    $assetUrl = null;

    foreach (['afiliado' => $comun, 'admin' => $admin] as $quien => $as) {
        $r = $get($mapPath, $as);
        $html = (string) $r->getContent();
        $check("{$quien}: mapa 200", $r->getStatusCode() === 200, 'status='.$r->getStatusCode());

        $intro = [];
        foreach (['GROUPS' => 'Grupos', 'FAMILIES' => 'Familias', 'CATEGORIES' => 'Categorías'] as $key => $word) {
            $expected = getenv('EXPECTED_'.$key);
            if ($expected !== false && $expected !== '') {
                $intro[] = str_contains($html, $fmt($expected).' '.$word);
            }
        }
        $check("{$quien}: los totales del mapa coinciden con el agregado previo", $intro !== [] && ! in_array(false, $intro, true));
        $check("{$quien}: etiquetas «sin categorías todavía» presentes", substr_count($html, 'sin categorías todavía') >= 2, 'ocurrencias='.substr_count($html, 'sin categorías todavía'));
        $check("{$quien}: sin códigos ni campos internos", ! preg_match('/CPV-\d/', $html) && ! str_contains($html, 'chamber_relevance') && ! str_contains($html, 'relevance_basis'));
        $check("{$quien}: enlace de vuelta al manual", str_contains($html, $manualPath.'#taxonomia-cpv') && str_contains($html, 'Volver al manual'));
        $check("{$quien}: el filtro está en la página", str_contains($html, 'id="cpv-map-filter"') && str_contains($html, 'x-data="cpvTaxonomyMap()"'));
        if (preg_match('/x-load-src="([^"]+)"/', $html, $m)) {
            $assetUrl = html_entity_decode($m[1]);
        }

        $r = $get($manualPath, $as);
        $check("{$quien}: el manual ofrece «Ver mapa de la taxonomía»", $r->getStatusCode() === 200 && str_contains((string) $r->getContent(), $mapPath) && str_contains((string) $r->getContent(), 'Ver mapa de la taxonomía'));
        $check("{$quien}: el manual tiene la sección del mapa", str_contains((string) $r->getContent(), 'id="mapa-taxonomia"'));
    }

    $check('se obtuvo la URL del componente del filtro', $assetUrl !== null, (string) parse_url((string) $assetUrl, PHP_URL_PATH));
    if ($assetUrl) {
        $file = public_path(ltrim((string) parse_url($assetUrl, PHP_URL_PATH), '/'));
        $check('el JS del filtro está publicado en public/', is_file($file) && str_contains((string) file_get_contents($file), 'cpvTaxonomyMap'), 'bytes='.(is_file($file) ? filesize($file) : 0));
        echo ''; // la respuesta HTTP real del asset la comprueba el operador con curl contra nginx
    }

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
fwrite($stdout, json_encode(['checks' => $checks, 'passed' => count($checks) - $failed, 'failed' => $failed, 'asset_path' => $assetUrl ? parse_url($assetUrl, PHP_URL_PATH) : null], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
exit($exit === 0 && $failed === 0 ? 0 : 1);

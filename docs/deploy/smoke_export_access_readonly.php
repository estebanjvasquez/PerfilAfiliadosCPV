<?php

/**
 * Prueba de humo de SOLO LECTURA de las descargas protegidas (CPV-INTEGRATED-EXPORT-ACCESS-DEPLOY,
 * Issue #2 `6265502896`, punto 4). Se ejecuta DENTRO del contenedor de la app, como www-data, por stdin:
 *
 *   docker compose exec -T -u www-data app php < docs/deploy/smoke_export_access_readonly.php
 *
 * NO usa cuentas reales ni ids reales: los usuarios se construyen EN MEMORIA (id 0, nunca guardados)
 * y solo se piden un id que no es de empresa (999999999) y uno inválido (`abc`). No descarga datos de
 * ninguna empresa. Todo corre en UNA transacción pgsql READ ONLY con ROLLBACK, sesión y caché en
 * memoria, y Mail/Notification en fake. Imprime solo estados HTTP, nombres de rutas y middleware, y
 * presencia de textos de la interfaz.
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
$viewQueries = 0;
Illuminate\Support\Facades\DB::listen(function ($q) use (&$viewQueries) {
    if (preg_match('/(capacity|catalogo|managementdet|management|resource|sustainability|clients|experience|facility|finance|inventory|machinery|presence)view/i', str_replace('"', '', $q->sql))) {
        $viewQueries++;
    }
});
$opened = false;
$exit = 0;
$base = 'https://pruebas.camarapetrolera.app';
$fake = '999999999';

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');
    $check('transaccion READ ONLY', $db->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on');

    // Cableado: rutas, middleware y política.
    $routes = app('router')->getRoutes();
    foreach (['filament.admin.export.pdf' => 'pdf', 'filament.admin.export.xls' => 'xlsx'] as $name => $suffix) {
        $route = $routes->getByName($name);
        $mw = $route ? implode(',', app('router')->gatherRouteMiddleware($route)) : '';
        $check("ruta {$name}", $route !== null && $route->uri() === "admin/descargas/empresas/{empresa}/{$suffix}", $route?->uri() ?? 'falta');
        $check("{$name}: Authenticate del panel + verified", str_contains($mw, 'Filament\Http\Middleware\Authenticate') && str_contains($mw, 'EnsureEmailIsVerified:filament.admin.auth.email-verification.prompt'), $mw);
        $check("{$name}: controlador", str_contains((string) $route?->getActionName(), 'EmpresaExportController'), (string) $route?->getActionName());
    }
    $policy = Illuminate\Support\Facades\Gate::getPolicyFor(App\Models\Empresa::class);
    $check('política EmpresaPolicy::export', $policy instanceof App\Policies\EmpresaPolicy && method_exists($policy, 'export'), $policy ? get_class($policy) : 'sin política');
    $check('PdfController retirado', ! class_exists(App\Http\Controllers\PdfController::class));

    // Autoload de modelos.
    $missing = [];
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! class_exists($class)) {
            $missing[] = $class;
        }
    }
    $check('autoload de app/Models', $missing === [], implode(',', $missing));

    $request = function (string $path, ?App\Models\User $as, string $method = 'GET', array $headers = []) use ($kernel, $base) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        if ($as) {
            Illuminate\Support\Facades\Auth::guard('web')->setUser($as);
        }
        $r = Illuminate\Http\Request::create($base.$path, $method);
        foreach ($headers as $k => $v) {
            $r->headers->set($k, $v);
        }

        return $kernel->handle($r);
    };
    $has = fn ($r, string $t) => str_contains((string) $r->getContent(), e($t)) || str_contains((string) $r->getContent(), $t);
    $loc = fn ($r) => (string) $r->headers->get('Location');

    // Invitado.
    $viewQueries = 0;
    $r = $request("/admin/descargas/empresas/{$fake}/pdf", null);
    $check('invitado: export PDF -> login del panel, sin consultar vistas', $r->getStatusCode() === 302 && str_ends_with($loc($r), '/admin/login') && $viewQueries === 0, 'status='.$r->getStatusCode().' location='.$loc($r)." vistas={$viewQueries}");
    $r = $request("/admin/descargas/empresas/{$fake}/xlsx", null, 'HEAD');
    $check('invitado: export XLSX (HEAD) -> login del panel', $r->getStatusCode() === 302 && str_ends_with($loc($r), '/admin/login') && $viewQueries === 0, 'status='.$r->getStatusCode());
    foreach (['pdf' => 'pdf', 'xls' => 'xlsx'] as $old => $new) {
        $r = $request("/{$old}/{$fake}", null);
        $check("ruta vieja /{$old}/{id}: solo redirige", $r->getStatusCode() === 302 && $loc($r) === "{$base}/admin/descargas/empresas/{$fake}/{$new}" && $viewQueries === 0, 'status='.$r->getStatusCode().' location='.$loc($r));
    }
    $r = $request('/esta-ruta-no-existe-smoke', null);
    $check('URL desconocida -> 404 «Página no encontrada» sin eco de la URL', $r->getStatusCode() === 404 && $has($r, 'Página no encontrada') && ! $has($r, 'esta-ruta-no-existe-smoke'), 'status='.$r->getStatusCode());

    // Usuario sin verificar (en memoria).
    $unverified = new App\Models\User();
    $unverified->forceFill(['id' => 0, 'name' => 'Smoke Sin Verificar (en memoria)', 'email' => 'smoke-unverified@invalid.test', 'email_verified_at' => null]);
    $unverified->setRelation('roles', collect());
    $unverified->setRelation('permissions', collect());
    $prompt = route('filament.admin.auth.email-verification.prompt');
    $r = $request("/admin/descargas/empresas/{$fake}/pdf", $unverified);
    $check('sin verificar: export -> pantalla de verificación, sin consultar vistas', $r->getStatusCode() === 302 && $loc($r) === $prompt && $viewQueries === 0, 'status='.$r->getStatusCode().' location='.$loc($r));
    $r = $request('/admin', $unverified);
    $check('sin verificar: panel -> pantalla de verificación', $r->getStatusCode() === 302 && $loc($r) === $prompt, 'status='.$r->getStatusCode());
    $r = $request(parse_url($prompt, PHP_URL_PATH), $unverified);
    $check('pantalla de verificación 200, sin 500', $r->getStatusCode() === 200 && $has($r, 'Verifique su correo electrónico'), 'status='.$r->getStatusCode());

    // Verificado sin vínculo ni permiso (en memoria): mismo 404 para id inexistente e inválido.
    $verified = new App\Models\User();
    $verified->forceFill(['id' => 0, 'name' => 'Smoke Verificado (en memoria)', 'email' => 'smoke-verified@invalid.test', 'email_verified_at' => now()]);
    $verified->setRelation('roles', collect());
    $verified->setRelation('permissions', collect());
    $bodies = [];
    foreach (["/admin/descargas/empresas/{$fake}/pdf", "/admin/descargas/empresas/{$fake}/xlsx", '/admin/descargas/empresas/abc/pdf'] as $path) {
        $r = $request($path, $verified);
        $bodies[] = md5((string) $r->getContent());
        $check("verificado sin acceso: 404 «Archivo no disponible» en {$path}", $r->getStatusCode() === 404 && $has($r, 'Archivo no disponible') && str_contains((string) $r->headers->get('Cache-Control'), 'no-store') && $viewQueries === 0, 'status='.$r->getStatusCode()." vistas={$viewQueries}");
    }
    $check('cuerpos 404 idénticos (sin oráculo de existencia)', count(array_unique($bodies)) === 1);
    $r = $request("/admin/descargas/empresas/{$fake}/pdf", $verified, 'GET', ['Accept' => 'application/json']);
    $json = json_decode((string) $r->getContent(), true);
    $check('JSON: 404 {"message":"Archivo no disponible"}', $r->getStatusCode() === 404 && ($json['message'] ?? null) === 'Archivo no disponible', 'status='.$r->getStatusCode());

    $check('ninguna consulta a vistas de reporte en toda la prueba', $viewQueries === 0, "vistas={$viewQueries}");
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

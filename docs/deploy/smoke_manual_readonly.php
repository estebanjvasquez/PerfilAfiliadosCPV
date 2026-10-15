<?php

/**
 * Prueba de humo AUTENTICADA y de SOLO LECTURA del manual en línea en staging (instrucción
 * 6190372221, punto 6). Se ejecuta DENTRO del contenedor de la app, como www-data, leído por stdin.
 *
 * Igual que smoke_task_0010c_readonly.php: usuarios EN MEMORIA (nunca guardados), sesión y caché en
 * memoria, Mail en fake, todo dentro de una transacción pgsql READ ONLY que termina en ROLLBACK. Solo
 * peticiones GET por el kernel HTTP. Imprime estados HTTP y presencia de anclas, nunca datos.
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

// Secciones nuevas o ampliadas en esta entrega (ver audit/mail_docs_user_manual_2026-10-15.md §3).
$anclas = ['que-es', 'ingresar', 'crear-cuenta', 'escritorio', 'formulario-por-pasos', 'botones-ventanas', 'contactos', 'recursos',
    'sistemas-de-gestion', 'experiencia', 'presencia-internacional', 'sostenibilidad', 'errores-frecuentes', 'contactar-camara', 'privacidad'];

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');
    $check('transaccion READ ONLY', $db->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on');

    $fuente = file_get_contents(resource_path('manual/manual_usuario.md'));
    $check('fuente desplegada = candidata', hash('sha256', str_replace("\r\n", "\n", $fuente)) === getenv('EXPECTED_MANUAL_SHA256'), 'sha256='.hash('sha256', str_replace("\r\n", "\n", $fuente)));

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

    foreach (['afiliado' => $comun, 'admin' => $admin] as $quien => $as) {
        $r = $get('/admin/manual-de-usuario', $as);
        $html = (string) $r->getContent();
        $check("{$quien}: manual 200", $r->getStatusCode() === 200, 'status='.$r->getStatusCode());
        $faltanId = array_values(array_filter($anclas, fn ($a) => ! str_contains($html, 'id="'.$a.'"')));
        $check("{$quien}: secciones ampliadas presentes", $faltanId === [], 'faltan='.implode(',', $faltanId));
        // El índice lista solo los títulos de nivel 2 (UserManualRenderer::decorate); las subsecciones
        // nuevas cuelgan de ellos y se comprueban arriba por su ancla.
        preg_match_all('/^## .*\{#([a-z0-9-]+)\}\s*$/m', $fuente, $m);
        $faltanIndice = array_values(array_filter($m[1], fn ($a) => ! str_contains($html, 'href="#'.$a.'"')));
        $check("{$quien}: índice enlaza a las ".count($m[1]).' secciones de nivel 2', $m[1] !== [] && $faltanIndice === [], 'faltan='.implode(',', $faltanIndice));
        $check("{$quien}: viewport móvil declarado", str_contains($html, 'name="viewport"'));
    }

    $r = $get('/admin', $comun);
    $check('afiliado: el panel enlaza al manual', in_array($r->getStatusCode(), [200], true) && str_contains((string) $r->getContent(), 'manual-de-usuario'), 'status='.$r->getStatusCode());

    $r = $get('/admin/ayuda-solicitudes-de-categoria', $admin);
    $check('admin: ayuda 200 con la sección de correo', $r->getStatusCode() === 200 && str_contains((string) $r->getContent(), 'correo-del-sistema'), 'status='.$r->getStatusCode());
    $r = $get('/admin/ayuda-solicitudes-de-categoria', $comun);
    $check('afiliado: ayuda de administración 403', $r->getStatusCode() === 403, 'status='.$r->getStatusCode());

    // El acceso de invitados se comprueba con HTTP real (curl a nginx), no aquí: sin sesión, el
    // kernel en proceso no reproduce la redirección del middleware Authenticate.

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

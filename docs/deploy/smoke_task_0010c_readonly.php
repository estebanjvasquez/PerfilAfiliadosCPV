<?php

/**
 * Prueba de humo AUTENTICADA y de SOLO LECTURA de TASK-0010C en staging (instruccion 6188477132,
 * punto 4). Se ejecuta DENTRO del contenedor de la app, como www-data, leido por stdin.
 *
 * NO usa ninguna cuenta real. Construye dos usuarios EN MEMORIA, nunca guardados:
 *   - uno con el rol super_admin cargado como relacion (el rol se lee de `roles`, no se asigna);
 *   - uno sin roles (cualquier no-administrador).
 * Los fija en el guard y pasa peticiones GET reales por el kernel HTTP. Todo corre dentro de UNA
 * transaccion pgsql READ ONLY que termina en ROLLBACK, con sesion y cache en memoria y Mail en
 * fake. No envia formularios, no pulsa "Reintentar aviso", no cambia el destinatario.
 *
 * Imprime solo estados HTTP y presencia de textos de la INTERFAZ, nunca contenido de la solicitud.
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
$huella = "md5(concat_ws('|', r.id, r.empresa_id, r.user_id, r.necesidad, r.justificacion, r.detalle, r.terminos_probados, r.context_snapshot::text, r.request_status, r.delivery_status, r.delivery_error, r.recipient_email, r.submitted_at, r.mail_sent_at, r.created_at, r.updated_at))";
$opened = false;
$exit = 0;

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');
    $check('transaccion READ ONLY', $db->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on');

    $antes = $db->selectOne("SELECT {$huella} AS h FROM taxonomy_category_requests r WHERE r.id = 1")->h ?? null;
    $intentosAntes = (int) $db->selectOne('SELECT count(*) AS c FROM taxonomy_category_request_delivery_attempts')->c;

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
    $has = fn ($r, string $t) => str_contains((string) $r->getContent(), e($t)) || str_contains((string) $r->getContent(), $t);

    $r = $get('/admin/solicitudes-de-categoria', $admin);
    $check('admin: lista 200', $r->getStatusCode() === 200, 'status='.$r->getStatusCode());
    $check('admin: lista con titulo y aviso de destinatario ausente', $has($r, 'Solicitudes de categoría') && $has($r, 'No hay correo responsable configurado'));

    $r = $get('/admin/solicitudes-de-categoria/1', $admin);
    $check('admin: detalle #1 200', $r->getStatusCode() === 200, 'status='.$r->getStatusCode());
    $check('admin: detalle muestra #1, Falló y el motivo', $has($r, 'Solicitud de categoría #1') && $has($r, 'Falló') && $has($r, 'No hay correo responsable configurado.'));
    $check('admin: detalle muestra la fila histórica', $has($r, 'Registrado antes del historial') && $has($r, 'no registrado'));
    $check('admin: detalle ofrece Reintentar aviso (no pulsado)', $has($r, 'Reintentar aviso'));
    $check('admin: guía con enlace a la configuración', $has($r, 'configuracion-de-solicitudes'));

    $r = $get('/admin/configuracion-de-solicitudes', $admin);
    $check('admin: configuración 200 y modo simulado log', $r->getStatusCode() === 200 && $has($r, 'Simulado (modo «log»)'), 'status='.$r->getStatusCode());

    $r = $get('/admin/ayuda-solicitudes-de-categoria', $admin);
    $check('admin: ayuda 200', $r->getStatusCode() === 200 && $has($r, 'Reintentar un aviso'), 'status='.$r->getStatusCode());

    $r = $get('/admin/taxonomy-selection-settings-page', $admin);
    $check('admin: límites 200 y enlaza a la configuración', $r->getStatusCode() === 200 && $has($r, 'configuracion-de-solicitudes'), 'status='.$r->getStatusCode());

    foreach (['/admin/solicitudes-de-categoria', '/admin/solicitudes-de-categoria/1', '/admin/configuracion-de-solicitudes', '/admin/ayuda-solicitudes-de-categoria'] as $path) {
        $r = $get($path, $comun);
        $check("no-admin: 403 en {$path}", $r->getStatusCode() === 403, 'status='.$r->getStatusCode());
    }

    $despues = $db->selectOne("SELECT {$huella} AS h FROM taxonomy_category_requests r WHERE r.id = 1")->h ?? null;
    $intentosDespues = (int) $db->selectOne('SELECT count(*) AS c FROM taxonomy_category_request_delivery_attempts')->c;
    $check('#1 con la misma huella antes y después', $antes !== null && $antes === $despues && $antes === '2e64f23555b01ad30f845bdd53cedc15', "huella={$despues}");
    $check('historial sin intentos nuevos', $intentosAntes === $intentosDespues, "antes={$intentosAntes} despues={$intentosDespues}");
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

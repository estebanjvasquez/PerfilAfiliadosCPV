<?php

/**
 * Prueba de humo AUTENTICADA y de SOLO LECTURA sobre staging, despues del despliegue del candidato
 * 0010. Instruccion 6174797031, punto 7: "authenticated manual and final request form labels
 * (open/inspect only, DO NOT submit)".
 *
 * Por que asi y no con un navegador logueado: entrar por el formulario de login exigiria la
 * contrasena de un usuario real, y crear un usuario de prueba seria una escritura en la base
 * compartida. Ninguna de las dos cosas esta autorizada. En su lugar, este script corre DENTRO del
 * contenedor de la app (como www-data, leido por stdin) y:
 *
 *   - toma un usuario afiliado EXISTENTE vinculado a una empresa, sin imprimir su identidad;
 *   - lo fija en el guard de esa misma ejecucion (no hay login, no se crea sesion persistente:
 *     sesion y cache se pasan a `array` en memoria);
 *   - pasa peticiones GET reales por el kernel HTTP de la app (rutas, middleware, Filament);
 *   - monta el RelationManager con Livewire y ABRE el modal "Solicitar revision", sin llamar
 *     nunca a la accion de envio;
 *   - todo dentro de UNA transaccion pgsql READ ONLY (PostgreSQL rechaza cualquier escritura) que
 *     termina en ROLLBACK, con Mail y Notification en fake.
 *
 * Imprime JSON: comprobaciones con resultado, y el HTML de la pagina del manual con el nombre y el
 * correo del usuario REEMPLAZADOS, para capturas de escritorio y movil.
 */

$stdout = fopen('php://stdout', 'w');

// El arranque va dentro de un try: una excepcion que escapa la atrapa el manejador de Laravel y el
// proceso sale con 0 (ya paso con el ejecutor del preflight y con la primera version de este).
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    // El kernel HTTP arranca proveedores que construyen el UrlGenerator, y este exige un request
    // ya registrado: sin esto el bootstrap falla con "Argument #2 ($request) must be of type Request".
    $app->instance('request', Illuminate\Http\Request::create('https://pruebas.camarapetrolera.app/', 'GET'));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    fwrite($stdout, json_encode(['checks' => [['check' => 'arranque', 'ok' => false,
        'detail' => get_class($e).': '.strtok($e->getMessage(), "\n")]], 'passed' => 0, 'failed' => 1])."\n");
    exit(1);
}

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager as RM;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Nada de esta ejecucion debe persistir: sesion y cache en memoria, correo y avisos falsos.
config(['session.driver' => 'array', 'cache.default' => 'array']);
Mail::fake();
Notification::fake();

$checks = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$checks) {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$base = 'https://pruebas.camarapetrolera.app';
$labels = [
    'SOLICITUD_ACTION_LABEL' => RM::SOLICITUD_ACTION_LABEL,
    'SOLICITUD_MODAL_HEADING' => RM::SOLICITUD_MODAL_HEADING,
    'SOLICITUD_NECESIDAD_LABEL' => RM::SOLICITUD_NECESIDAD_LABEL,
    'SOLICITUD_JUSTIFICACION_LABEL' => RM::SOLICITUD_JUSTIFICACION_LABEL,
    'SOLICITUD_TERMINOS_LABEL' => RM::SOLICITUD_TERMINOS_LABEL,
];

$db = DB::connection('pgsql');
$manualHtml = null;
$exit = 0;
$opened = false;

try {
    $db->beginTransaction();
    $opened = true;
    $db->unprepared('SET TRANSACTION READ ONLY');
    $db->unprepared("SET LOCAL statement_timeout = '60s'");
    $check('transaccion READ ONLY', $db->selectOne('SHOW transaction_read_only')->transaction_read_only === 'on');

    $requestsBefore = (int) $db->selectOne('SELECT count(*) AS c FROM taxonomy_category_requests')->c;

    // Un afiliado existente con empresa vinculada. Se elige el primero por id; no se imprime.
    $pair = $db->selectOne(
        'SELECT eu.user_id, eu.empresa_id FROM empresa_user eu
           JOIN users u ON u.id = eu.user_id JOIN empresas e ON e.id = eu.empresa_id
          ORDER BY eu.empresa_id, eu.user_id LIMIT 1'
    );
    $user = User::findOrFail($pair->user_id);
    $empresa = Empresa::findOrFail($pair->empresa_id);
    $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->implode(',') : '?';
    $check('usuario afiliado existente con empresa vinculada', true, 'roles='.$roles);

    $get = function (string $path, ?User $as) use ($kernel, $base) {
        Auth::forgetGuards();
        if ($as) {
            Auth::guard('web')->setUser($as);
        }
        $response = $kernel->handle(Request::create($base.$path, 'GET'));

        return $response;
    };

    // Invitado: el manual exige autenticacion.
    $r = $get('/admin/manual-de-usuario', null);
    $check('invitado -> manual redirige a login', in_array($r->getStatusCode(), [302, 303], true)
        && str_contains((string) $r->headers->get('Location'), '/admin/login'), 'status='.$r->getStatusCode());

    $r = $get('/admin/login', null);
    $check('login responde 200', $r->getStatusCode() === 200, 'status='.$r->getStatusCode());

    // Autenticado.
    $r = $get('/admin', $user);
    $check('panel /admin autenticado responde 200', $r->getStatusCode() === 200, 'status='.$r->getStatusCode());

    $r = $get('/admin/manual-de-usuario', $user);
    $manual = (string) $r->getContent();
    $check('manual autenticado responde 200', $r->getStatusCode() === 200, 'status='.$r->getStatusCode());
    foreach ($labels as $const => $text) {
        $check("manual cita {$const}", str_contains($manual, e($text)) || str_contains($manual, $text));
    }
    $check('manual sin marcador provisional', ! str_contains($manual, 'PROVISIONAL-TASK-0010A'));

    $r = $get('/admin/empresas/'.$empresa->getKey().'/edit', $user);
    $check('edicion de su empresa responde 200', $r->getStatusCode() === 200, 'status='.$r->getStatusCode());

    // Modal: montar y ABRIR, nunca enviar.
    Auth::forgetGuards();
    Auth::guard('web')->setUser($user);
    $t = Livewire::actingAs($user)->test(RM::class, ['ownerRecord' => $empresa, 'pageClass' => EditEmpresa::class]);
    $check('relation manager: boton con la etiqueta final', str_contains($t->html(), e(RM::SOLICITUD_ACTION_LABEL)));
    $t->call('mountTableAction', 'solicitarRevisionCategoria');
    $modal = $t->html();
    $check('modal abierto (mountedTableActions)', $t->get('mountedTableActions') === ['solicitarRevisionCategoria']);
    foreach (['SOLICITUD_MODAL_HEADING', 'SOLICITUD_NECESIDAD_LABEL', 'SOLICITUD_JUSTIFICACION_LABEL', 'SOLICITUD_TERMINOS_LABEL'] as $const) {
        $check("modal muestra {$const}", str_contains($modal, e($labels[$const])));
    }
    $check('modal muestra la descripcion (no crea categoria)', str_contains($modal, e(RM::SOLICITUD_MODAL_DESCRIPTION)));
    $check('modal muestra el boton de envio (no pulsado)', str_contains($modal, e(RM::SOLICITUD_SUBMIT_LABEL)));

    $requestsAfter = (int) $db->selectOne('SELECT count(*) AS c FROM taxonomy_category_requests')->c;
    $check('ninguna solicitud creada', $requestsBefore === 0 && $requestsAfter === 0, "antes={$requestsBefore} despues={$requestsAfter}");
    // MailFake solo expone conteos por clase o con aserciones de PHPUnit (no instalado aqui):
    // se leen sus dos listas internas, las de todo lo enviado y todo lo encolado.
    $outgoing = (fn () => count($this->mailables) + count($this->queuedMailables))->call(Mail::getFacadeRoot());
    $check('ningun correo enviado ni encolado (fake)', $outgoing === 0, 'salientes='.$outgoing);

    // HTML del manual para capturas, sin identidad del usuario.
    $redact = array_filter([(string) $user->name, (string) $user->email, rawurlencode((string) $user->name), urlencode((string) $user->name)]);
    $manualHtml = str_replace($redact, 'Usuario afiliado', $manual);
    $manualHtml = preg_replace('/<meta name="csrf-token"[^>]*>/', '', $manualHtml);
    $check('HTML del manual redactado (nombre y correo ausentes)', ! str_contains($manualHtml, (string) $user->email)
        && ($user->name === '' || ! str_contains($manualHtml, (string) $user->name)));
} catch (Throwable $e) {
    $exit = 1;
    $check('excepcion', false, get_class($e).': '.strtok($e->getMessage(), "\n"));
} finally {
    if ($opened) {
        $db->rollBack();
    }
}

$failed = count(array_filter($checks, fn ($c) => ! $c['ok']));
fwrite($stdout, json_encode([
    'checks' => $checks,
    'passed' => count($checks) - $failed,
    'failed' => $failed,
    'manual_html_b64' => $manualHtml === null ? null : base64_encode($manualHtml),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");

exit($exit === 0 && $failed === 0 ? 0 : 1);

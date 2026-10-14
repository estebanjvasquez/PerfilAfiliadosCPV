<?php

/**
 * Ejecutor de SOLO LECTURA de docs/deploy/preflight_integration_candidate_0010.sql contra la base
 * que resuelve la conexion `pgsql` de la app de staging. Instruccion 6174401867.
 *
 * Se ejecuta DENTRO del contenedor de la app ya desplegada, leyendo este script por stdin y el SQL
 * desde una variable de entorno, para no escribir ningun archivo en el contenedor vivo:
 *
 *   docker compose exec -T \
 *     -e PREFLIGHT_SQL_B64="$(base64 -w0 preflight.sql)" \
 *     -e PREFLIGHT_SQL_SHA256="$(sha256sum preflight.sql | cut -d' ' -f1)" \
 *     app php < run_readonly_preflight.php
 *
 * Por que no psql: `\echo` es un meta-comando de psql, no SQL, y la imagen no trae psql. Y por que
 * la conexion de Laravel: la instruccion pide la base que resuelve la conexion `pgsql` de staging,
 * y esa es exactamente la que usa `migrate --force` en el despliegue. Ninguna credencial pasa por
 * aqui ni se imprime: la configuracion ya esta en el contenedor.
 *
 * GARANTIAS, en orden:
 *   1. el SQL recibido tiene el sha256 declarado (si no, no se ejecuta nada);
 *   2. cada bloque `-- @block` es UNA sola sentencia que empieza por SELECT o WITH y que, quitados
 *      literales y comentarios, no contiene ninguna palabra de escritura o de control de sesion;
 *   3. todo corre dentro de UNA transaccion abierta como READ ONLY (lo hace cumplir PostgreSQL, no
 *      este script), verificada con SHOW antes del primer bloque;
 *   4. la transaccion termina SIEMPRE con ROLLBACK, tambien si todo salio bien;
 *   5. un error SQL detiene todo (codigo 5); un veredicto que no sea OK / INFO / BASELINE es una
 *      PARADA (codigo 6). Nada se omite ni se reintenta.
 */

$sql = base64_decode((string) getenv('PREFLIGHT_SQL_B64'), true);
if ($sql === false || $sql === '') {
    fwrite(STDERR, "PREFLIGHT_SQL_B64 ausente o invalido\n");
    exit(2);
}
if (hash('sha256', $sql) !== (string) getenv('PREFLIGHT_SQL_SHA256')) {
    fwrite(STDERR, "El sha256 del SQL recibido no coincide con PREFLIGHT_SQL_SHA256: no se ejecuta nada\n");
    exit(2);
}

// --- Partir por marcadores y validar ANTES de abrir ninguna conexion ---------------------------
preg_match_all('/^-- @block\s+(\S+)\s*\|\s*(.+)$/m', $sql, $markers, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
if ($markers === []) {
    fwrite(STDERR, "El SQL no tiene marcadores -- @block\n");
    exit(2);
}

$forbidden = '/\b(INSERT|UPDATE|DELETE|MERGE|UPSERT|ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|COPY|CALL|DO|SET|RESET|'
    .'VACUUM|ANALYZE|LOCK|COMMENT|REFRESH|REINDEX|CLUSTER|NOTIFY|LISTEN|UNLISTEN|PREPARE|EXECUTE|DEALLOCATE|DISCARD|'
    .'BEGIN|START|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|INTO|nextval|setval|pg_terminate_backend|pg_cancel_backend|'
    .'pg_advisory_lock|pg_advisory_xact_lock|lo_import|lo_export|dblink|pg_read_file|pg_sleep)\b/i';

$blocks = [];
foreach ($markers as $i => $marker) {
    $start = $marker[0][1] + strlen($marker[0][0]);
    $end = isset($markers[$i + 1]) ? $markers[$i + 1][0][1] : strlen($sql);
    $body = substr($sql, $start, $end - $start);

    // Quitar comentarios de linea y despues el ';' final. Lo que queda es la sentencia.
    $statement = trim(preg_replace('/^\s*--.*$/m', '', $body));
    $statement = rtrim($statement);
    if (str_ends_with($statement, ';')) {
        $statement = rtrim(substr($statement, 0, -1));
    }

    // Para la inspeccion, ademas, quitar literales: los veredictos dicen "CREATE TABLE" en texto.
    $probe = preg_replace("/'(?:[^']|'')*'/", "''", $statement);
    $probe = preg_replace('/--[^\n]*/', '', $probe);

    if (! preg_match('/^\s*(SELECT|WITH)\b/i', $probe)) {
        fwrite(STDERR, "Bloque {$marker[1][0]}: no empieza por SELECT/WITH; no se ejecuta nada\n");
        exit(2);
    }
    if (str_contains($probe, ';')) {
        fwrite(STDERR, "Bloque {$marker[1][0]}: mas de una sentencia; no se ejecuta nada\n");
        exit(2);
    }
    if (preg_match($forbidden, $probe, $hit)) {
        fwrite(STDERR, "Bloque {$marker[1][0]}: palabra no permitida «{$hit[1]}»; no se ejecuta nada\n");
        exit(2);
    }

    $blocks[] = ['id' => $marker[1][0], 'title' => trim($marker[2][0]), 'sql' => $statement];
}

// Ensayo: solo particion y guarda, sin arrancar Laravel ni abrir conexion.
if (getenv('PREFLIGHT_DRY_RUN') === '1') {
    foreach ($blocks as $block) {
        fwrite(STDOUT, "bloque {$block['id']} aceptado (".strlen($block['sql'])." bytes)\n");
    }
    exit(0);
}

// --- Arrancar Laravel para resolver la conexion `pgsql` tal como la ve la app -------------------
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = Illuminate\Support\Facades\DB::connection('pgsql');

$out = static function (string $line = ''): void {
    fwrite(STDOUT, $line."\n");
};
$scalar = static function ($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return str_replace(["\r", "\n"], ' ', (string) $value);
};

$out('# PREFLIGHT DE SOLO LECTURA - instruccion 6174401867');
$out('ejecutado_utc='.gmdate('Y-m-d\TH:i:s\Z'));
$out('sql_sha256='.hash('sha256', $sql));
$out('bloques='.count($blocks));
$out('conexion=pgsql');
$out('conexion_por_defecto='.config('database.default'));
$out('tabla_de_registro='.(is_array(config('database.migrations')) ? (config('database.migrations')['table'] ?? 'migrations') : config('database.migrations')));
$out('app_env='.config('app.env'));

$stops = [];
$counts = [];
$exit = 0;

$conn->beginTransaction();
try {
    // PostgreSQL hace cumplir esto: cualquier escritura dentro de la transaccion falla con 25006.
    $conn->unprepared('SET TRANSACTION READ ONLY');
    $conn->unprepared("SET LOCAL statement_timeout = '60s'");

    $ro = $conn->selectOne('SHOW transaction_read_only');
    if (($ro->transaction_read_only ?? null) !== 'on') {
        throw new RuntimeException('La transaccion no quedo en READ ONLY');
    }
    $out('transaccion_read_only=on (verificado con SHOW antes del primer bloque)');

    $dbName = $conn->selectOne('SELECT current_database() AS d')->d;
    $out('base_coincide_con_config_pgsql='.($dbName === config('database.connections.pgsql.database') ? 'true' : 'false'));
    if ($dbName !== config('database.connections.pgsql.database')) {
        $stops[] = 'identidad: current_database() no coincide con la base configurada en pgsql';
    }

    foreach ($blocks as $block) {
        $out();
        $out("== {$block['id']} | {$block['title']}");
        try {
            $rows = $conn->select($block['sql']);
        } catch (Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo ?? [];
            $out('ERROR_SQL sqlstate='.($info[0] ?? $e->getCode()).' mensaje='.strtok((string) ($info[2] ?? 'sin detalle'), "\n"));
            throw new RuntimeException("Error SQL en el bloque {$block['id']}", 5);
        }

        $out('filas='.count($rows));
        foreach ($rows as $row) {
            $cells = [];
            foreach ((array) $row as $column => $value) {
                $cells[] = $column.'='.$scalar($value);
            }
            $out('  '.implode(' | ', $cells));

            $verdict = $row->veredicto ?? null;
            if ($verdict === null) {
                continue;
            }
            $kind = strtok((string) $verdict, ':') ?: (string) $verdict;
            $kind = trim(preg_replace('/\s*\(.*$/', '', $kind));
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if (! in_array($kind, ['OK', 'INFO', 'BASELINE'], true)) {
                $first = (array) $row;
                $stops[] = "{$block['id']}: ".$scalar(reset($first)).' -> '.$verdict;
            }
        }
    }
} catch (Throwable $e) {
    $exit = $e->getCode() === 5 ? 5 : 1;
    $out();
    $out('ABORTADO: '.$e->getMessage());
} finally {
    // SIEMPRE rollback: no hay nada que confirmar.
    $conn->rollBack();
    $out();
    $out('transaccion_cerrada=ROLLBACK');
}

ksort($counts);
$out();
$out('== RESUMEN');
foreach ($counts as $kind => $n) {
    $out("veredicto_{$kind}={$n}");
}
$out('paradas='.count($stops));
foreach ($stops as $stop) {
    $out('  PARADA '.$stop);
}

if ($exit === 0 && $stops !== []) {
    $exit = 6;
}
$out('resultado='.($exit === 0 ? 'COMPATIBLE' : ($exit === 6 ? 'PARADA' : 'ERROR')));
$out('codigo_salida='.$exit);
exit($exit);

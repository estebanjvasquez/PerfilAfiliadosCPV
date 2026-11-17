<?php
// TASK-0033 E5: listados para las decisiones de la Cámara. SOLO lectura (READ ONLY + ROLLBACK).
// Salida JSON con dos partes: "publico" (ids, conteos, catálogos; va al repo) y "privado" (nombres de empresas y texto de
// solicitudes; se guarda SOLO en docs/uat/privado/, excluida de git). Sin RIF, correos, teléfonos ni usuarios.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$pub = ['generado' => gmdate('c')];
$priv = ['generado' => $pub['generado']];
DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');
try {
    $pub['transaccion_read_only'] = DB::selectOne("select current_setting('transaction_read_only') as v")->v;
    $pub['sectores'] = DB::select('select id, name from sectors order by id');
    $pub['servicios_x'] = DB::select("select s.id, s.sectors_id from services s where upper(trim(s.name)) = 'X' order by s.id");

    $sinSector = DB::select('select e.id, e.name, e.sector_secundario_id,
            (select string_agg(distinct s.sectors_id::text, \',\' order by s.sectors_id::text) from empresa_sector_service ess join services s on s.id = ess.service_id where ess.empresa_id = e.id) as sectores_por_servicio,
            (select count(*) from empresa_sector_service ess join services s on s.id = ess.service_id where ess.empresa_id = e.id and upper(trim(s.name)) <> \'X\') as servicios_no_x,
            (select count(*) from empresa_sector_service ess join services s on s.id = ess.service_id where ess.empresa_id = e.id and upper(trim(s.name)) = \'X\') as servicios_x
        from empresas e where e.status_id = 1 and e.sector_principal_id is null order by e.id');
    $pub['empresas_sin_sector_principal'] = array_map(fn ($r) => ['id' => $r->id, 'sector_secundario_id' => $r->sector_secundario_id, 'sectores_por_servicio' => $r->sectores_por_servicio, 'servicios_no_x' => $r->servicios_no_x, 'servicios_x' => $r->servicios_x], $sinSector);
    $priv['empresas_sin_sector_principal'] = array_map(fn ($r) => ['id' => $r->id, 'nombre' => $r->name], $sinSector);

    $x = DB::select("with x as (select ess.empresa_id, s.sectors_id, s.id as service_id from empresa_sector_service ess join services s on s.id = ess.service_id where upper(trim(s.name)) = 'X')
        select x.empresa_id, e.name, x.sectors_id, e.sector_principal_id, e.sector_secundario_id,
            exists (select 1 from empresa_sector_service e2 join services s2 on s2.id = e2.service_id where e2.empresa_id = x.empresa_id and s2.sectors_id = x.sectors_id and upper(trim(s2.name)) <> 'X') as otro_servicio_en_sector,
            (x.sectors_id in (e.sector_principal_id, e.sector_secundario_id)) as cubierto_por_sector_declarado
        from x join empresas e on e.id = x.empresa_id order by x.empresa_id, x.sectors_id");
    $prio = fn ($r) => $r->otro_servicio_en_sector ? 'baja' : ($r->cubierto_por_sector_declarado ? 'media' : 'alta');
    $pub['vinculos_x'] = array_map(fn ($r) => ['empresa_id' => $r->empresa_id, 'sector_id' => $r->sectors_id, 'sector_principal_id' => $r->sector_principal_id, 'sector_secundario_id' => $r->sector_secundario_id, 'otro_servicio_en_sector' => (bool) $r->otro_servicio_en_sector, 'cubierto_por_sector_declarado' => (bool) $r->cubierto_por_sector_declarado, 'prioridad' => $prio($r)], $x);
    $priv['vinculos_x'] = array_map(fn ($r) => ['empresa_id' => $r->empresa_id, 'nombre' => $r->name, 'sector_id' => $r->sectors_id, 'prioridad' => $prio($r)], $x);

    $req = DB::select('select id, empresa_id, justificacion, request_status, delivery_status, submitted_at, necesidad, terminos_probados from taxonomy_category_requests order by id');
    $pub['solicitudes_categoria'] = array_map(fn ($r) => ['id' => $r->id, 'empresa_id' => $r->empresa_id, 'justificacion' => $r->justificacion, 'request_status' => $r->request_status, 'delivery_status' => $r->delivery_status, 'submitted_at' => $r->submitted_at], $req);
    $priv['solicitudes_categoria'] = array_map(fn ($r) => ['id' => $r->id, 'necesidad' => $r->necesidad, 'terminos_probados' => $r->terminos_probados], $req);

    // ¿La Cámara figura como empresa del Perfil? (decisión pendiente de tasks/0013). Solo ids.
    $pub['empresas_con_nombre_de_camara'] = array_map(fn ($r) => $r->id, DB::select("select id from empresas where status_id = 1 and unaccent(lower(name)) like '%camara petrolera%' order by id"));
    // Alias de «Balancín» (tasks/0014): ¿existen hoy como términos del diccionario? Solo conteos.
    foreach (['pumping unit', 'pump jack', 'balancines', 'balancin', 'balancín'] as $t) {
        $pub['terminos_diccionario'][$t] = DB::selectOne("select count(*) as n from taxonomy_terms where unaccent(lower(term)) = unaccent(lower(?))", [$t])->n;
    }
} catch (Throwable $e) {
    $pub['error'] = get_class($e) . ': ' . substr($e->getMessage(), 0, 400);
} finally {
    DB::rollBack();
}
echo json_encode(['publico' => $pub, 'privado' => $priv], JSON_UNESCAPED_UNICODE), "\n";

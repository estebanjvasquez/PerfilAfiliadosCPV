<?php
// TASK-0033: sonda de SOLO lectura en la BD compartida de staging (E1, E2, E5). Todo dentro de una transacción
// READ ONLY que termina en ROLLBACK. Solo conteos e ids; ningún nombre, correo ni dato personal.
// Uso (desde el host de staging): docker compose exec -T -u www-data app php < sonda_solo_lectura.php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$out = ['generado' => gmdate('c')];
$q = fn (string $sql) => DB::select($sql);
$one = fn (string $sql) => (array) (DB::selectOne($sql) ?? []);
DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');
try {
    $out['transaccion_read_only'] = $one("select current_setting('transaction_read_only') as v")['v'];
    $out['migraciones'] = $one('select count(*) as total, max(batch) as ultimo_lote, max(migration) as ultima from migrations');

    // Perfil de Afiliados: criterio de pertenencia verificado en TASK-0032 (status_id = 1).
    $out['empresas_por_status'] = $q('select status_id, count(*) as n from empresas group by status_id order by status_id');
    $out['empresas_activas_sin_sector_principal'] = $one('select count(*) as n from empresas where status_id = 1 and sector_principal_id is null');
    $out['empresas_activas_sin_sector_principal_ni_secundario'] = $one('select count(*) as n from empresas where status_id = 1 and sector_principal_id is null and sector_secundario_id is null');

    // Servicio «X» (definiciones de tasks/0013, 2026-11-07).
    $out['servicios_x'] = $one("select count(*) as servicios from services where upper(trim(name)) = 'X'");
    $out['x_vinculos'] = $one("select count(*) as vinculos, count(distinct ess.empresa_id) as empresas from empresa_sector_service ess join services s on s.id = ess.service_id where upper(trim(s.name)) = 'X'");
    $x = "with x as (select ess.empresa_id, s.sectors_id from empresa_sector_service ess join services s on s.id = ess.service_id where upper(trim(s.name)) = 'X'),
          solo as (select x.* from x where not exists (select 1 from empresa_sector_service e2 join services s2 on s2.id = e2.service_id
                    where e2.empresa_id = x.empresa_id and s2.sectors_id = x.sectors_id and upper(trim(s2.name)) <> 'X'))";
    $out['x_unica_pertenencia_al_sector'] = $one("$x select count(*) as vinculos, count(distinct empresa_id) as empresas from solo");
    $out['x_unica_cubierta_por_sector_declarado'] = $one("$x select count(*) as vinculos, count(distinct solo.empresa_id) as empresas from solo join empresas e on e.id = solo.empresa_id where solo.sectors_id in (e.sector_principal_id, e.sector_secundario_id)");
    $out['x_unica_sin_cubrir'] = $one("$x select count(*) as vinculos, count(distinct solo.empresa_id) as empresas from solo join empresas e on e.id = solo.empresa_id where solo.sectors_id is distinct from e.sector_principal_id and solo.sectors_id is distinct from e.sector_secundario_id");
    $out['x_unica_sin_cubrir_empresas_sin_sector_declarado'] = $one("$x select count(*) as vinculos, count(distinct solo.empresa_id) as empresas from solo join empresas e on e.id = solo.empresa_id where e.sector_principal_id is null and e.sector_secundario_id is null");
    $out['x_empresas_activas_con_x'] = $one("select count(distinct ess.empresa_id) as n from empresa_sector_service ess join services s on s.id = ess.service_id join empresas e on e.id = ess.empresa_id where upper(trim(s.name)) = 'X' and e.status_id = 1");

    // chamber_empresa: afiliaciones a OTRAS cámaras (regla del propietario); solo conteos.
    $out['chamber_empresa'] = $one('select count(*) as filas, count(distinct empresa_id) as empresas from chamber_empresa');

    // Tablas de CIRA presentes y sus conteos.
    $tablas = array_map(fn ($r) => $r->table_name, $q("select table_name from information_schema.tables where table_schema = 'public' and (table_name like 'cira%' or table_name like 'taxonomy_category_request%' or table_name in ('empresa_search_documents','agent_learnings','taxonomy_candidate_concept_links','taxonomy_concept_relations'))  order by 1"));
    foreach ($tablas as $t) {
        $out['conteos'][$t] = $one("select count(*) as n from \"$t\"")['n'];
    }
    if (in_array('cira_search_log', $tablas, true)) {
        $out['cira_search_log_rango'] = $one('select min(id) as min_id, max(id) as max_id, min(created_at) as desde, max(created_at) as hasta from cira_search_log');
        $out['cira_search_log_columnas'] = array_map(fn ($r) => $r->column_name, $q("select column_name from information_schema.columns where table_name = 'cira_search_log' order by ordinal_position"));
        $out['cira_search_log_por_dia'] = $q("select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD') as dia, count(*) as n from cira_search_log where created_at >= now() - interval '10 days' group by 1 order by 1");
    }
    if (in_array('cira_search_feedback', $tablas, true)) {
        $out['cira_search_feedback_por_voto'] = $q('select vote, voter_kind, count(*) as n from cira_search_feedback group by 1, 2 order by 1, 2');
    }
    if (in_array('taxonomy_category_requests', $tablas, true)) {
        $out['solicitudes_categoria_por_estado'] = $q('select request_status, count(*) as n from taxonomy_category_requests group by 1 order by 1');
    }
    if (in_array('taxonomy_candidate_concept_links', $tablas, true)) {
        $out['candidatos_concepto_por_estado'] = $q('select status, count(*) as n from taxonomy_candidate_concept_links group by 1 order by 1');
    }
    if (in_array('taxonomy_concept_relations', $tablas, true)) {
        $out['relaciones_concepto_por_estado'] = $q('select status, count(*) as n from taxonomy_concept_relations group by 1 order by 1');
    }
    foreach (array_filter($tablas, fn ($t) => str_starts_with($t, 'cira_evaluation')) as $t) {
        $cols = array_map(fn ($r) => $r->column_name, $q("select column_name from information_schema.columns where table_name = '$t'"));
        if (in_array('status', $cols, true)) {
            $out['evaluacion_por_estado'][$t] = $q("select status, count(*) as n from \"$t\" group by 1 order by 1");
        }
    }

    // Seguridad (E2), solo metadatos.
    $out['vistas_public'] = $q("select c.relname as vista, coalesce(array_to_string(c.reloptions, ','), '') as opciones,
            has_table_privilege('anon', c.oid, 'SELECT') as anon_select, has_table_privilege('authenticated', c.oid, 'SELECT') as authenticated_select
            from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'v' order by 1");
    $out['rls_tablas_public'] = $one("select count(*) filter (where c.relrowsecurity) as con_rls, count(*) as total,
            count(*) filter (where has_table_privilege('anon', c.oid, 'SELECT')) as anon_select
            from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r'");
    $out['permisos_shield_role'] = $one("select count(*) as permisos, (select count(*) from role_has_permissions rhp join permissions p2 on p2.id = rhp.permission_id where p2.name like '%_role') as vinculos from permissions p where p.name like '%_role'");
    $out['roles'] = $q('select r.name as rol, count(rhp.permission_id) as permisos, (select count(*) from model_has_roles m where m.role_id = r.id) as usuarios from roles r left join role_has_permissions rhp on rhp.role_id = r.id group by r.id, r.name order by r.name');
    $out['delete_user_fuera_de_super_admin'] = $q("select r.name as rol, p.name as permiso from roles r join role_has_permissions rhp on rhp.role_id = r.id join permissions p on p.id = rhp.permission_id where p.name in ('delete_user','delete_any_user','force_delete_user','force_delete_any_user') and r.name <> 'super_admin' order by 1, 2");
    $out['contact_empresa_huerfanas'] = $one('select count(*) as n from contact_empresa ce where not exists (select 1 from contacts c where c.id = ce.contact_id) or not exists (select 1 from empresas e where e.id = ce.empresa_id)');
    $out['usuarios'] = $one('select count(*) as total, count(*) filter (where email_verified_at is null) as sin_verificar from users');
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . substr($e->getMessage(), 0, 400);
} finally {
    DB::rollBack();
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

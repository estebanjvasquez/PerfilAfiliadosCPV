<?php
// TASK-0033: segunda sonda de SOLO lectura (READ ONLY + ROLLBACK). E4: response_ms de cira_search_log por período;
// E2: políticas RLS con anon/public y permisos *_role. Solo agregados; sin textos de búsqueda ni datos personales.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$out = ['generado' => gmdate('c')];
DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');
try {
    $out['transaccion_read_only'] = DB::selectOne("select current_setting('transaction_read_only') as v")->v;
    $out['response_ms_por_periodo'] = DB::select("
        select periodo, agent_tool, count(*) as n, count(response_ms) as con_ms,
               percentile_cont(0.5) within group (order by response_ms) as p50,
               percentile_cont(0.95) within group (order by response_ms) as p95,
               min(response_ms) as min, max(response_ms) as max
        from (select *, case
                when created_at < '2026-11-16 18:40:06+00' then 'a_antes_V1b'
                when created_at < '2026-11-16 19:12:49+00' then 'b_V1b_c6c70402'
                when created_at < '2026-11-17 00:00:00+00' then 'c_2d0a1ee8_16nov'
                else 'd_2d0a1ee8_17nov' end as periodo from cira_search_log) t
        group by 1, 2 order by 1, 2");
    $out['engine_version_por_periodo'] = DB::select("select left(coalesce(engine_version, ''), 120) as engine_version, count(*) as n, min(id) as min_id, max(id) as max_id from cira_search_log where created_at >= '2026-11-16' group by 1 order by 3");
    $out['source_por_periodo'] = DB::select("select source, count(*) as n from cira_search_log where created_at >= '2026-11-16' group by 1 order by 1");
    $out['politicas_rls_anon_o_public'] = DB::select("select tablename, policyname, cmd, array_to_string(roles, ',') as roles from pg_policies where schemaname = 'public' and (roles && array['anon','public','authenticated']::name[]) order by 1, 2");
    $out['politicas_rls_total'] = DB::selectOne("select count(*) as n from pg_policies where schemaname = 'public'")->n;
    $out['permisos_role'] = DB::select("select p.name, count(rhp.role_id) as roles from permissions p left join role_has_permissions rhp on rhp.permission_id = p.id where p.name like '%\\_role' or p.name like '%::role' group by p.name order by p.name");
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . substr($e->getMessage(), 0, 400);
} finally {
    DB::rollBack();
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

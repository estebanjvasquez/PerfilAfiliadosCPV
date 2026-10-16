<?php
// Read-only, aggregate counts only (no row content): rows whose actor is NULL in the five module tables,
// the signature a failed first save leaves on PostgreSQL. READ ONLY transaction, rolled back.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$db->beginTransaction();
$db->unprepared('SET TRANSACTION READ ONLY');
echo 'read_only=', $db->selectOne('SHOW transaction_read_only')->transaction_read_only, "\n";
foreach (['management', 'assets', 'experiences', 'presences', 'sustainabilities'] as $t) {
    $r = $db->selectOne("select count(*) total, count(*) filter (where user_id is null) null_actor, count(*) filter (where user_id is null and created_at >= '2026-09-01') null_actor_since_sep, count(distinct empresa_id) companies from {$t}");
    echo "{$t}: total={$r->total} null_actor={$r->null_actor} null_actor_since_2026-09-01={$r->null_actor_since_sep} companies={$r->companies}\n";
}
$db->rollBack();

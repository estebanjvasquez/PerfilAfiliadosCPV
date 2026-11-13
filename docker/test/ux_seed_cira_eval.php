<?php

/*
 * TASK-0027 (Issue #2 `6852945146`): synthetic data for the browser check of the CIRA evaluation module.
 *
 * - three synthetic companies (ZZZ UX CIRA ALFA/BETA/GAMMA);
 * - one open campaign (ZZZ-UX-T27) with the synthetic affiliate invited as evaluator E-01;
 * - one CIRA search exactly as the CIRA flow logs it (cira_search_log), owned by the browser session the check will
 *   use (session_ref = test-<fixed uuid>), and one prior 👍 from that same session (cira_search_feedback).
 * The CIRA engine and n8n are NOT reached: the browser check answers their requests itself. Prints the ids for it.
 *
 * Runs only inside the disposable stack: it refuses unless APP_ENV=testing and pgsql is testdb/cpv_test.
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.pgsql');
if (app()->environment() !== 'testing' || ($cfg['host'] ?? null) !== 'testdb' || ($cfg['database'] ?? null) !== 'cpv_test') {
    fwrite(fopen('php://stderr', 'w'), "REFUSING: not the disposable test database\n");
    exit(3);
}

$db = Illuminate\Support\Facades\DB::connection('pgsql');
$affiliate = App\Models\User::where('email', 'zzz-ux-affiliate@example.test')->firstOrFail();
Illuminate\Support\Facades\Auth::login($affiliate); // Empresa::created() reads the session user

$ids = [];
foreach (['ALFA', 'BETA', 'GAMMA'] as $m) {
    $ids[] = (int) App\Models\Empresa::firstOrCreate(['rif' => 'J-0000027'.strlen($m).'-'.ord($m[0]) % 10], ['name' => 'ZZZ UX CIRA '.$m])->id;
}

$campaign = App\Models\Cira\CiraEvalCampaign::query()->firstOrCreate(['code' => 'ZZZ-UX-T27'], ['name' => 'Campaña sintética de evaluación', 'status' => 'open']);
$inv = App\Models\Cira\CiraEvalInvitation::query()->where('campaign_id', $campaign->id)->where('user_id', $affiliate->id)->first()
    ?? tap(new App\Models\Cira\CiraEvalInvitation())->forceFill(['campaign_id' => $campaign->id, 'user_id' => $affiliate->id, 'pseudonym' => 'E-01'])->save();

$sid = 'test-11111111-2222-4333-8444-555555555555';
$evidence = array_map(fn ($id, $i) => ['id' => $id, 'pos' => $i + 1, 'rel' => 'declarado', 'via' => 'servicio', 'puntaje' => 0.9 - $i / 10, 'fuentes' => [['campo' => 'servicio', 'valor' => 'bombas']]], $ids, array_keys($ids));
$logId = (int) $db->table('cira_search_log')->insertGetId([
    'source' => 'n8n_chat', 'session_ref' => $sid, 'user_input' => 'ZZZ UX mantenimiento de bombas', 'agent_tool' => 'search_empresas',
    'agent_params' => json_encode(['query' => 'mantenimiento de bombas', 'explain' => true]), 'result_count' => 3,
    'result_empresa_ids' => '{'.implode(',', $ids).'}', 'result_evidence' => json_encode($evidence),
    'engine_version' => 'zzz-ux-engine', 'response_ms' => 640,
]);
$db->table('cira_search_feedback')->insert(['search_log_id' => $logId, 'empresa_id' => $ids[0], 'rank_position' => 1, 'vote' => 1, 'voter_kind' => 'staff', 'voter_ref' => $sid]);

echo "CIRA_EMPRESAS=".implode(',', $ids)."\n";
echo "CIRA_SID={$sid}\n";
echo "CIRA_LOG_ID={$logId}\n";

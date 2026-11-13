<?php

namespace Tests\Feature\Cira;

use App\Filament\Pages\CiraEvaluationFormPage;
use App\Filament\Pages\CiraEvaluationGuidePage;
use App\Filament\Pages\CiraEvaluationHomePage;
use App\Filament\Pages\CiraEvaluationReportPage;
use App\Filament\Resources\CiraEvalCampaignResource;
use App\Filament\Resources\CiraEvalCampaignResource\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\CiraEvaluationResource;
use App\Filament\Resources\CiraEvaluationResource\Pages\ListCiraEvaluations;
use App\Models\Cira\CiraEvalCampaign;
use App\Models\Cira\CiraEvalInvitation;
use App\Models\Cira\CiraEvaluation;
use App\Models\Cira\CiraSearchLog;
use App\Models\Contact;
use App\Models\Empresa;
use App\Models\User;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0027 (Issue #2 `6852945146`): the CIRA evaluation module. Synthetic users, companies, searches and votes in the
 * disposable PostgreSQL (one transaction per test, rolled back). The CIRA engine, n8n and the shared database are never
 * reached: a "search" is a cira_search_log row written here exactly as the CIRA flow writes it.
 */
class CiraEvaluationModuleTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private CiraEvaluationService $svc;

    private CiraEvalCampaign $campaign;

    private User $evaluator;

    private User $otherEvaluator;

    private User $outsider;

    private User $admin;

    private CiraEvalInvitation $invitation;

    private CiraEvalInvitation $otherInvitation;

    /** @var list<int> */
    private array $companies = [];

    private string $sid;

    private int $logId;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->svc = app(CiraEvaluationService::class);

        $this->evaluator = User::factory()->create();
        $this->otherEvaluator = User::factory()->create();
        $this->outsider = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['ALFA', 'BETA', 'GAMMA', 'DELTA'] as $m) {
            $this->companies[] = $this->company($m);
        }

        $this->campaign = $this->makeCampaign('ZZZ-T27', 'open');
        $this->invitation = $this->invite($this->campaign, $this->evaluator, 'E-01');
        $this->otherInvitation = $this->invite($this->campaign, $this->otherEvaluator, 'E-02');

        $this->sid = 'test-'.Str::uuid();
        $this->logId = $this->searchLog($this->sid, 'ZZZT27 mantenimiento de bombas', array_slice($this->companies, 0, 3));
    }

    // ------------------------------------------------------------------ fixtures

    private function company(string $marker): int
    {
        $owner = User::factory()->create();
        $sector = DB::table('sectors')->insertGetId(['name' => 'ZZZT27SEC'.$marker, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner);
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => 'ZZZT27 EMPRESA '.$marker, 'phone' => '+58-212-5550000',
            'sector_principal_id' => $sector, 'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();

        return (int) $empresa->id;
    }

    private function makeCampaign(string $code, string $status, $opens = null, $closes = null): CiraEvalCampaign
    {
        $c = new CiraEvalCampaign(['code' => $code, 'name' => 'Campaña '.$code, 'status' => $status, 'opens_at' => $opens, 'closes_at' => $closes]);
        $c->save();

        return $c;
    }

    private function invite(CiraEvalCampaign $campaign, User $user, string $pseudonym, array $extra = []): CiraEvalInvitation
    {
        $i = new CiraEvalInvitation();
        $i->forceFill(['campaign_id' => $campaign->id, 'user_id' => $user->id, 'pseudonym' => $pseudonym] + $extra)->save();

        return $i;
    }

    /** A search exactly as the CIRA flow logs it (TASK-0013 schema). */
    private function searchLog(string $sid, string $input, array $ids, array $extra = []): int
    {
        $evidence = array_map(fn ($id, $i) => ['id' => $id, 'pos' => $i + 1, 'rel' => 'declarado', 'via' => 'servicio', 'puntaje' => 0.9 - $i / 10, 'fuentes' => [['campo' => 'servicio', 'valor' => 'ZZZT27 bombas']]], $ids, array_keys($ids));

        return (int) DB::table('cira_search_log')->insertGetId(array_merge([
            'source' => 'n8n_chat', 'session_ref' => $sid, 'user_input' => $input, 'agent_tool' => 'search_empresas',
            'agent_params' => json_encode(['query' => $input, 'explain' => true]), 'result_count' => count($ids),
            'result_empresa_ids' => '{'.implode(',', $ids).'}', 'result_evidence' => json_encode($evidence),
            'engine_version' => 'zzz-t27-engine', 'response_ms' => 850,
        ], $extra));
    }

    private function vote(int $logId, ?int $empresaId, int $vote, string $voterRef, array $extra = []): void
    {
        DB::table('cira_search_feedback')->insert(['search_log_id' => $logId, 'empresa_id' => $empresaId, 'rank_position' => $extra['rank_position'] ?? null,
            'vote' => $vote, 'voter_kind' => 'staff', 'voter_ref' => $voterRef] + array_diff_key($extra, ['rank_position' => 1]));
    }

    private function form(User $user, array $query)
    {
        return Livewire::actingAs($user)->withQueryParams($query)->test(CiraEvaluationFormPage::class);
    }

    /** The harness lost the component: the server refused the request (403/404). */
    private function refused($component): bool
    {
        if ($component->instance() !== null) {
            return false;
        }
        try {
            $component->assertForbidden();
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $component->assertNotFound();
        }

        return true;
    }

    // ------------------------------------------------------------------ routes and access

    #[Test]
    public function cira_test_links_straight_to_the_panel_pages_and_adds_no_public_route(): void
    {
        $page = file_get_contents(public_path('cira-test/index.html'));
        $path = fn (string $url) => parse_url($url, PHP_URL_PATH);
        $this->assertStringContainsString('href="'.$path(CiraEvaluationGuidePage::getUrl()).'"', $page);
        $this->assertStringContainsString('href="'.$path(CiraEvaluationHomePage::getUrl()).'"', $page);
        $this->assertStringContainsString('a.href = "'.$path(CiraEvaluationFormPage::getUrl()).'"', $page);
        foreach (app('router')->getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->uri(), 'cira-test'), 'no Laravel route under the static /cira-test/ folder: '.$route->uri());
        }
    }

    #[Test]
    public function anonymous_visitors_are_sent_to_login(): void
    {
        foreach ([CiraEvaluationHomePage::getUrl(), CiraEvaluationFormPage::getUrl(), CiraEvaluationGuidePage::getUrl(), CiraEvaluationReportPage::getUrl(), CiraEvaluationResource::getUrl()] as $url) {
            $this->get($url)->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        }
    }

    #[Test]
    public function each_role_reaches_only_its_pages(): void
    {
        $evaluatorPages = [CiraEvaluationHomePage::getUrl(), CiraEvaluationFormPage::getUrl(), CiraEvaluationGuidePage::getUrl()];
        $panelPages = [CiraEvaluationReportPage::getUrl(), CiraEvaluationResource::getUrl(), CiraEvalCampaignResource::getUrl()];

        foreach ($evaluatorPages as $u) {
            $this->actingAs($this->evaluator)->get($u)->assertOk();
            $this->actingAs($this->outsider)->get($u)->assertForbidden();
        }
        foreach ($panelPages as $u) {
            $this->actingAs($this->evaluator)->get($u)->assertForbidden();
            $this->actingAs($this->outsider)->get($u)->assertForbidden();
            $this->actingAs($this->admin)->get($u)->assertOk();
        }
        // The panel role is not an evaluator unless invited; it may read the guide.
        $this->actingAs($this->admin)->get(CiraEvaluationHomePage::getUrl())->assertForbidden();
        $this->actingAs($this->admin)->get(CiraEvaluationGuidePage::getUrl())->assertOk();
    }

    #[Test]
    public function revoked_expired_or_closed_access_is_refused(): void
    {
        $url = CiraEvaluationHomePage::getUrl();
        $this->invitation->forceFill(['revoked_at' => now()])->save();
        $this->actingAs($this->evaluator)->get($url)->assertForbidden();

        $this->invitation->forceFill(['revoked_at' => null, 'expires_at' => now()->subMinute()])->save();
        $this->actingAs($this->evaluator)->get($url)->assertForbidden();

        $this->invitation->forceFill(['expires_at' => null])->save();
        $this->campaign->forceFill(['status' => 'closed'])->save();
        $this->actingAs($this->evaluator)->get($url)->assertForbidden();

        $this->campaign->forceFill(['status' => 'open', 'opens_at' => now()->addDay()])->save();
        $this->actingAs($this->evaluator)->get($url)->assertForbidden();

        $this->campaign->forceFill(['opens_at' => null])->save();
        $this->actingAs($this->evaluator)->get($url)->assertOk();
    }

    #[Test]
    public function access_revoked_while_the_page_is_open_stops_the_save(): void
    {
        $page = $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid]);
        $this->assertFalse($this->refused($page));
        $this->invitation->forceFill(['revoked_at' => now()])->save();

        $page->set('data.rating', 'correcta'); // one request: the refusal can come on rehydrate
        if (! $this->refused($page)) {
            $page->call('submit');
            $this->assertTrue($this->refused($page), 'refused after hydrate');
        }
        $this->assertSame(0, CiraEvaluation::query()->count());
    }

    // ------------------------------------------------------------------ the evaluation itself

    #[Test]
    public function an_evaluation_links_exactly_its_search_and_keeps_an_immutable_snapshot_without_touching_logs_or_votes(): void
    {
        $this->vote($this->logId, $this->companies[0], 1, $this->sid, ['rank_position' => 1]);
        $logBefore = (array) DB::table('cira_search_log')->find($this->logId);
        $feedbackBefore = DB::table('cira_search_feedback')->count();

        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid, 'test' => 'CPV-01'])
            ->assertSee('ZZZT27 mantenimiento de bombas')
            ->assertSee('ZZZT27 EMPRESA ALFA')
            ->assertSee('incluye su voto')
            ->fillForm([
                'test_code' => 'CPV-01', 'rating' => 'parcial',
                'extra_empresa_ids' => [(string) $this->companies[2]],
                'missing_empresa_ids' => [(string) $this->companies[3]],
                'reason' => 'faltan_empresas', 'comment' => 'Falta DELTA; GAMMA no hace bombas.',
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $e = CiraEvaluation::query()->sole();
        $this->assertSame($this->logId, (int) $e->search_log_id);
        $this->assertSame('enviada', $e->status);
        $this->assertNotNull($e->submitted_at);
        $this->assertSame($this->invitation->id, (int) $e->invitation_id);
        $this->assertSame([$this->companies[2]], $e->extra_empresa_ids);
        $this->assertSame([$this->companies[3]], $e->missing_empresa_ids);
        $this->assertSame('ZZZT27 mantenimiento de bombas', $e->query_text);
        $this->assertSame($this->logId, $e->snapshot['search_log_id']);
        $this->assertSame('zzz-t27-engine', $e->snapshot['engine_version']);
        $this->assertSame([1, 2, 3], array_column($e->snapshot['results'], 'position'));
        $this->assertSame(array_slice($this->companies, 0, 3), array_column($e->snapshot['results'], 'empresa_id'));
        $this->assertSame('ZZZT27 EMPRESA ALFA', $e->snapshot['results'][0]['name']);
        $this->assertSame(CiraEvaluationService::snapshotHash($e->snapshot), $e->snapshot_sha256, 'hash verifies after the JSONB round trip');

        $this->assertSame($logBefore, (array) DB::table('cira_search_log')->find($this->logId), 'the search log is untouched');
        $this->assertSame($feedbackBefore, DB::table('cira_search_feedback')->count(), 'no vote is created or copied');
    }

    #[Test]
    public function later_ranking_changes_do_not_rewrite_the_evidence_and_the_database_refuses_to_edit_it(): void
    {
        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])
            ->fillForm(['test_code' => 'CPV-06', 'rating' => 'correcta'])->call('submit')->assertHasNoFormErrors();
        $e = CiraEvaluation::query()->sole();
        $snapshot = $e->snapshot;

        // The engine "changes its mind" about this search afterwards.
        DB::table('cira_search_log')->where('id', $this->logId)->update(['result_empresa_ids' => '{'.$this->companies[3].'}', 'engine_version' => 'zzz-t27-v2']);
        $this->form($this->evaluator, ['evaluacion' => $e->id])->assertSee('zzz-t27-engine')->assertDontSee('zzz-t27-v2');
        $this->assertSame($snapshot, $e->fresh()->snapshot);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('pgsql')->transaction(fn () => DB::table('cira_evaluations')->where('id', $e->id)->update(['snapshot' => json_encode(['forged' => true])]));
    }

    #[Test]
    public function a_deleted_search_log_leaves_the_snapshot_and_the_evaluation(): void
    {
        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])
            ->fillForm(['test_code' => 'CPV-02', 'rating' => 'incorrecta'])->call('submit')->assertHasNoFormErrors();
        DB::table('cira_search_log')->where('id', $this->logId)->delete();
        $e = CiraEvaluation::query()->sole();
        $this->assertNull($e->search_log_id);
        $this->assertSame($this->logId, $e->snapshot['search_log_id']);
        $this->actingAs($this->admin)->get(CiraEvaluationResource::getUrl('view', ['record' => $e->id]))->assertOk()->assertSee('registro original eliminado');
    }

    #[Test]
    public function nobody_reads_or_evaluates_another_session_search_by_its_id(): void
    {
        // The other evaluator knows the id but not the session: nothing of the search is shown, nothing is linked.
        $page = $this->form($this->otherEvaluator, ['log' => $this->logId, 'sid' => 'test-'.Str::uuid()])
            ->assertSet('logRefused', true)->assertSet('logId', null)
            ->assertDontSee('ZZZT27 mantenimiento de bombas')->assertDontSee('ZZZT27 EMPRESA ALFA');
        $page->fillForm(['test_code' => 'CPV-01', 'rating' => 'correcta'])->call('submit')->assertHasFormErrors(['query_text']);
        $this->assertSame(0, CiraEvaluation::query()->count());

        // Without any sid, same answer.
        $this->form($this->otherEvaluator, ['log' => $this->logId])->assertSet('logRefused', true)->assertDontSee('ZZZT27 EMPRESA ALFA');
    }

    #[Test]
    public function nobody_opens_another_evaluators_evaluation(): void
    {
        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])
            ->fillForm(['test_code' => 'CPV-01', 'rating' => 'correcta', 'comment' => 'ZZZT27 privado'])->call('submit');
        $id = CiraEvaluation::query()->sole()->id;

        $this->assertTrue($this->refused($this->form($this->otherEvaluator, ['evaluacion' => $id])));
        $this->actingAs($this->otherEvaluator)->get(CiraEvaluationHomePage::getUrl())->assertOk()->assertDontSee('ZZZT27 privado');
    }

    #[Test]
    public function the_ids_that_decide_what_is_evaluated_cannot_be_changed_from_the_browser(): void
    {
        $page = $this->form($this->otherEvaluator, []);
        foreach (['logId' => $this->logId, 'sid' => $this->sid, 'evaluationId' => 1, 'clientToken' => (string) Str::uuid()] as $prop => $value) {
            try {
                $page->set($prop, $value);
                $this->fail("{$prop} accepted a value from the browser");
            } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function invalid_input_is_rejected(): void
    {
        $base = ['log' => $this->logId, 'sid' => $this->sid];
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-01'])->call('submit')->assertHasFormErrors(['rating']);
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-01', 'rating' => 'correcta', 'extra_empresa_ids' => [(string) $this->companies[3]]])->call('submit')->assertHasFormErrors(['extra_empresa_ids']);
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-01', 'rating' => 'correcta', 'missing_empresa_ids' => ['999999999']])->call('submit')->assertHasFormErrors(['missing_empresa_ids']);
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-01', 'rating' => 'correcta', 'comment' => str_repeat('x', 2001)])->call('submit')->assertHasFormErrors(['comment']);
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-99', 'rating' => 'correcta'])->call('submit')->assertHasFormErrors(['test_code']);
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-01', 'rating' => 'excelente'])->call('submit')->assertHasFormErrors(['rating']);
        $this->assertSame(0, CiraEvaluation::query()->count());

        // A draft may be saved without a rating.
        $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-01'])->call('saveDraft')->assertHasNoFormErrors();
        $this->assertSame('borrador', CiraEvaluation::query()->sole()->status);
    }

    #[Test]
    public function double_clicks_reloads_and_a_second_tab_never_create_a_second_row(): void
    {
        $base = ['log' => $this->logId, 'sid' => $this->sid];
        $page = $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-04', 'rating' => 'parcial']);
        $page->call('submit')->call('submit');
        $this->assertSame(1, CiraEvaluation::query()->count(), 'double click');

        $tab2 = $this->form($this->evaluator, $base)->fillForm(['test_code' => 'CPV-04', 'rating' => 'incorrecta']);
        $tab2->call('submit')->assertHasNoFormErrors();
        $this->assertSame(1, CiraEvaluation::query()->count(), 'second tab updates the same row');
        $this->assertSame('incorrecta', CiraEvaluation::query()->sole()->rating);

        // Reopening by the link resumes the same evaluation.
        $this->form($this->evaluator, $base + ['test' => 'CPV-04'])->assertSet('evaluationId', CiraEvaluation::query()->sole()->id)->assertFormSet(['rating' => 'incorrecta']);

        // Same client token through the service: idempotent.
        $token = (string) Str::uuid();
        $log = $this->svc->claimableLog($this->logId, $this->sid);
        $a = $this->svc->save($this->invitation, ['test_code' => 'CPV-05', 'rating' => 'correcta'], null, $log, null, $token, true);
        $b = $this->svc->save($this->invitation, ['test_code' => 'CPV-05', 'rating' => 'parcial'], null, $log, null, $token, true);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(2, CiraEvaluation::query()->count());
    }

    #[Test]
    public function a_draft_is_resumed_from_my_tests(): void
    {
        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])
            ->fillForm(['test_code' => 'CPV-07', 'comment' => 'ZZZT27 sigo luego'])->call('saveDraft');
        $e = CiraEvaluation::query()->sole();

        $this->actingAs($this->evaluator)->get(CiraEvaluationHomePage::getUrl())->assertOk()->assertSee('Borrador')->assertSee('borrador');
        $this->form($this->evaluator, ['evaluacion' => $e->id])->assertFormSet(['test_code' => 'CPV-07', 'comment' => 'ZZZT27 sigo luego'])
            ->fillForm(['rating' => 'correcta'])->call('submit')->assertHasNoFormErrors();
        $this->assertSame('enviada', $e->fresh()->status);
        $this->assertSame(1, CiraEvaluation::query()->count());
    }

    #[Test]
    public function searches_without_results_without_log_or_with_an_engine_error_are_shown_as_they_are(): void
    {
        $empty = $this->searchLog($this->sid, 'ZZZT27 nada', [], ['result_count' => 0]);
        $this->form($this->evaluator, ['log' => $empty, 'sid' => $this->sid])->assertSee('no devolvió resultados')
            ->fillForm(['test_code' => 'CPV-05', 'rating' => 'correcta'])->call('submit')->assertHasNoFormErrors();
        $this->assertSame([], CiraEvaluation::query()->where('search_log_id', $empty)->sole()->snapshot['results']);

        $failed = $this->searchLog($this->sid, 'ZZZT27 caida', [], ['result_count' => null, 'error' => 'timeout del motor', 'response_ms' => 30000]);
        $this->form($this->evaluator, ['log' => $failed, 'sid' => $this->sid])->assertSee('timeout del motor')->assertSee('30000 ms');

        // Not logged at all: the evaluator writes the query; nothing is invented.
        $this->form($this->evaluator, [])->assertSee('Búsqueda sin registro')
            ->fillForm(['test_code' => 'CPV-03', 'rating' => 'parcial', 'query_text' => 'ZZZT27 empresa por nombre'])->call('submit')->assertHasNoFormErrors();
        $free = CiraEvaluation::query()->whereNull('search_log_id')->sole();
        $this->assertNull($free->snapshot['results']);
        $this->assertNull($free->snapshot['engine_version']);
        $this->assertNotEmpty($free->snapshot['unavailable']);
    }

    #[Test]
    public function html_in_comments_and_queries_is_shown_as_text(): void
    {
        $xssLog = $this->searchLog($this->sid, '<img src=x onerror=alert(2)>', array_slice($this->companies, 0, 1));
        $this->form($this->evaluator, ['log' => $xssLog, 'sid' => $this->sid])
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->fillForm(['test_code' => 'CPV-08', 'rating' => 'incorrecta', 'comment' => '<script>alert(1)</script>'])->call('submit')->assertHasNoFormErrors();
        $e = CiraEvaluation::query()->sole();
        $this->assertSame('<script>alert(1)</script>', $e->comment, 'stored as written');

        $this->actingAs($this->admin)->get(CiraEvaluationResource::getUrl('view', ['record' => $e->id]))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
        $this->actingAs($this->admin)->get(CiraEvaluationReportPage::getUrl())->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ votes, report, panel

    #[Test]
    public function votes_are_read_from_cira_test_never_duplicated_and_unlinked_ones_are_flagged(): void
    {
        $other = 'test-'.Str::uuid();
        $this->vote($this->logId, $this->companies[0], 1, $this->sid, ['rank_position' => 1]);
        $this->vote($this->logId, $this->companies[1], -1, $other, ['rank_position' => 2, 'reason' => 'otro_sector']);
        $this->vote($this->logId, $this->companies[3], -1, $other.'#x');                    // not a result of this search
        $this->vote($this->logId, null, -1, $this->sid.'#falta-1', ['reason' => 'falta_empresa', 'comment' => 'ZZZT27 falta una']);
        $before = DB::table('cira_search_feedback')->count();

        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])->assertSee('incluye su voto')->assertSee('ZZZT27 falta una')
            ->fillForm(['test_code' => 'CPV-01', 'rating' => 'parcial'])->call('submit');
        $this->assertSame($before, DB::table('cira_search_feedback')->count());

        $report = $this->svc->report($this->campaign);
        $this->assertSame(['up' => 1, 'down' => 3, 'missing_reports' => 1, 'linked' => 3, 'unlinked' => 1], $report['votes']);
        $e = CiraEvaluation::query()->sole();
        $this->actingAs($this->admin)->get(CiraEvaluationResource::getUrl('view', ['record' => $e->id]))->assertSee('Voto no vinculado');
    }

    #[Test]
    public function the_report_counts_judgements_and_the_chamber_decides_explicitly(): void
    {
        $log2 = $this->searchLog($s2 = 'test-'.Str::uuid(), 'ZZZT27 otra', array_slice($this->companies, 1, 2));
        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])->fillForm(['test_code' => 'CPV-01', 'rating' => 'correcta'])->call('submit');
        $this->form($this->otherEvaluator, ['log' => $log2, 'sid' => $s2])->fillForm(['test_code' => 'CPV-01', 'rating' => 'incorrecta', 'missing_empresa_ids' => [(string) $this->companies[0]]])->call('submit');
        $this->form($this->evaluator, [])->fillForm(['test_code' => 'CPV-02', 'query_text' => 'ZZZT27 borrador'])->call('saveDraft');

        $r = $this->svc->report($this->campaign);
        $this->assertSame([1, 0, 1, 0, 2, null], [$r['tests']['CPV-01']['correcta'], $r['tests']['CPV-01']['parcial'], $r['tests']['CPV-01']['incorrecta'], $r['tests']['CPV-01']['drafts'], $r['tests']['CPV-01']['evaluators'], $r['tests']['CPV-01']['decision']]);
        $this->assertSame(1, $r['tests']['CPV-02']['drafts']);
        $this->assertSame(['sent' => 2, 'drafts' => 1, 'evaluators' => 2, 'invited' => 2, 'without_log' => 1], $r['totals']);
        $this->assertSame($this->companies[0], $r['missing'][0]['empresa_id']);

        // Nothing is decided by the counts; only an explicit decision by the panel role.
        $this->assertNull($r['tests']['CPV-01']['decision']);
        Livewire::actingAs($this->admin)->test(CiraEvaluationReportPage::class)->assertSee('no declara aceptado')
            ->set('decisions.CPV-01.decision', 'observado')->set('decisions.CPV-01.note', 'ZZZT27 revisar faltantes')->call('decide', 'CPV-01')
            ->set('decisions.CPV-02.decision', 'aceptado-todo')->call('decide', 'CPV-02')->assertHasErrors(['decisions.CPV-02.decision']);
        $this->assertSame('observado', DB::table('cira_eval_test_decisions')->where('campaign_id', $this->campaign->id)->where('test_code', 'CPV-01')->value('decision'));
        $this->assertSame(1, DB::table('cira_eval_test_decisions')->count());

        $this->assertTrue($this->refused(Livewire::actingAs($this->evaluator)->test(CiraEvaluationReportPage::class)));
    }

    #[Test]
    public function the_panel_classifies_findings_and_manages_invitations_with_pseudonyms(): void
    {
        $this->form($this->evaluator, ['log' => $this->logId, 'sid' => $this->sid])->fillForm(['test_code' => 'CPV-08', 'rating' => 'incorrecta'])->call('submit');
        $e = CiraEvaluation::query()->sole();

        Livewire::actingAs($this->admin)->test(ListCiraEvaluations::class)->call('loadTable')
            ->callTableAction('classify', $e, ['finding_category' => 'explicacion', 'reviewer_note' => 'ZZZT27 nota'])->assertHasNoTableActionErrors();
        $e->refresh();
        $this->assertSame(['explicacion', $this->admin->id], [$e->finding_category, (int) $e->reviewed_by]);
        $this->assertSame('incorrecta', $e->rating, 'the evaluator judgement is not edited by the panel');

        $this->assertTrue($this->refused(Livewire::actingAs($this->evaluator)->test(ListCiraEvaluations::class)));

        $newbie = User::factory()->create();
        $rm = Livewire::actingAs($this->admin)->test(InvitationsRelationManager::class, ['ownerRecord' => $this->campaign, 'pageClass' => CiraEvalCampaignResource\Pages\EditCiraEvalCampaign::class]);
        $rm->callTableAction('invite', data: ['user_id' => $newbie->id])->assertHasNoTableActionErrors();
        $inv = CiraEvalInvitation::query()->where('user_id', $newbie->id)->sole();
        $this->assertSame('E-03', $inv->pseudonym);
        $rm->callTableAction('revoke', $inv);
        $this->assertNotNull($inv->fresh()->revoked_at);
        $rm->callTableAction('invite', data: ['user_id' => $newbie->id]);
        $this->assertSame(['E-03', null], [$inv->fresh()->pseudonym, $inv->fresh()->revoked_at], 'reactivated with the same pseudonym');

        $unverified = User::factory()->unverified()->create();
        $rm->callTableAction('invite', data: ['user_id' => $unverified->id]);
        $this->assertSame(0, CiraEvalInvitation::query()->where('user_id', $unverified->id)->count());
    }

    // ------------------------------------------------------------------ schema and platform guarantees

    #[Test]
    public function the_migration_is_idempotent_reversible_and_closed_to_the_data_api_roles(): void
    {
        $db = DB::connection('pgsql');
        foreach (['anon', 'authenticated'] as $role) {
            if ($db->selectOne('select 1 as x from pg_roles where rolname = ?', [$role]) === null) {
                $db->statement("CREATE ROLE {$role} NOLOGIN");
            }
        }
        $db->statement('GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated');
        $migration = require database_path('migrations/2026_11_13_130000_create_cira_evaluation_tables.php');
        $migration->up(); // again: idempotent, and now revokes the API roles
        foreach (['cira_eval_campaigns', 'cira_eval_invitations', 'cira_evaluations', 'cira_eval_test_decisions'] as $t) {
            $row = $db->selectOne("select relrowsecurity rls, has_table_privilege('anon', oid, 'SELECT') anon, has_table_privilege('authenticated', oid, 'SELECT') auth from pg_class where relname = ? and relnamespace = 'public'::regnamespace", [$t]);
            $this->assertSame([true, false, false], [(bool) $row->rls, (bool) $row->anon, (bool) $row->auth], $t);
        }

        CiraEvaluation::query()->delete();
        $migration->down();
        $this->assertSame(0, (int) $db->selectOne("select count(*) n from pg_class where relname like 'cira_eval%' and relnamespace = 'public'::regnamespace")->n);
        $this->assertSame(1, (int) $db->selectOne("select count(*) n from pg_class where relname = 'cira_search_log'")->n, 'TASK-0013 tables untouched');
        $migration->up();
        $this->assertSame(4, (int) $db->selectOne("select count(*) n from pg_class where relname in ('cira_eval_campaigns', 'cira_eval_invitations', 'cira_evaluations', 'cira_eval_test_decisions')")->n);
    }

    #[Test]
    public function the_cira_tables_of_task_0013_stay_read_only_for_this_application(): void
    {
        $log = CiraSearchLog::query()->findOrFail($this->logId);
        $this->expectException(\LogicException::class);
        $log->forceFill(['user_input' => 'x'])->save();
    }

    #[Test]
    public function livewire_requests_are_csrf_protected_and_the_cira_test_page_links_safely(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => in_array('POST', $r->methods(), true) && str_ends_with($r->uri(), 'livewire/update'));
        $this->assertNotNull($route, 'the Livewire update endpoint');
        $this->assertContains('web', $route->gatherMiddleware());
        $web = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'] ?? [];
        $this->assertTrue(collect($web)->contains(fn ($m) => is_string($m) && (str_contains($m, 'VerifyCsrfToken') || str_contains($m, 'ValidateCsrfToken'))), 'CSRF middleware in the web group');

        $page = file_get_contents(public_path('cira-test/index.html'));
        $this->assertStringContainsString('function addEvalLink(row)', $page);
        $this->assertStringContainsString("a.textContent = \"Evaluar esta búsqueda\"", $page);
        $this->assertStringContainsString('/^[0-9]{1,18}$/.test(logId)', $page);
    }

    #[Test]
    public function the_guide_lists_the_eight_tests_and_the_time_needed(): void
    {
        $res = $this->actingAs($this->evaluator)->get(CiraEvaluationGuidePage::getUrl())->assertOk()->assertSee('35–50');
        foreach (array_keys(config('cira_evaluation.tests')) as $code) {
            $res->assertSee($code);
        }
        $this->assertCount(8, config('cira_evaluation.tests'));
    }
}

<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\Pages\ListEmpresas;
use App\Filament\Resources\EmpresaResource\RelationManagers\ChambersRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\UsersRelationManager;
use App\Models\Contact;
use App\Models\Empresa;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * CPV-SEC-01 (Issue #2 `6293112309` / `6293203444`): actor × entrypoint × company access matrix, EXECUTED in the
 * disposable PostgreSQL with synthetic users and companies (ALFA, BETA).
 *
 * Actors:
 * - guest;
 * - unverified affiliate;
 * - affiliate of ALFA and affiliate of BETA (the affiliate role's company permissions);
 * - linked read-only staff (view permissions on every resource and page, linked to ALFA);
 * - super_admin;
 * - verified user without any permission;
 * - affiliate whose link to ALFA was revoked;
 * - holder of the role-management permission only (characterization, see the SEC-01 register).
 *
 * Every panel resource page (index, create, and edit/view of a real record) and every custom page is requested as
 * every actor. The matrix is written as JSON evidence; the assertions are the invariants:
 * - a guest always goes to the login, and an unverified user to the verification prompt;
 * - a 200 always matches the server-side gate for that actor (Resource::can*, Page::canAccess);
 * - a user without permissions reaches no resource page;
 * - company data stays inside the linked company: other company → 404, revoked link → 404, also for staff.
 * Then the Livewire paths a browser can drive: list, global search, edit with forged protected fields, mixed-company bulk
 * selections and relation-manager actions with another company's records. Allowed paths are checked too.
 */
class AccessMatrixTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** The affiliate role's company-related permissions (its catalog permissions are a separate configuration item). */
    private const AFFILIATE = [
        'view_any_empresa', 'view_empresa', 'update_empresa', 'delete_any_empresa', 'export_empresa',
        'view_any_asset', 'view_asset', 'create_asset', 'update_asset', 'delete_asset', 'delete_any_asset', 'export_asset',
        'view_any_experience', 'view_experience', 'create_experience', 'update_experience', 'delete_experience', 'delete_any_experience', 'export_experience',
        'view_any_management', 'view_management', 'create_management', 'update_management', 'delete_management', 'delete_any_management', 'export_management',
        'view_any_presence', 'view_presence', 'create_presence', 'update_presence', 'delete_presence', 'delete_any_presence', 'export_presence',
        'view_any_chamber', 'view_any_sector', 'view_any_service', 'view_dashboard', 'view_account_widget', 'view_my_profile', 'view_settings',
    ];

    /**
     * R2-REV-03: BUSINESS-LEVEL approved outcomes for high-value entrypoints, independent of the gate under test. Keys
     * are entrypoint labels; values are actor => one of 'allow' (must be 200), 'deny' (must be forbidden OR not_found —
     * the policy is denial; which safe status is an implementation nuance), 'login' or 'verify'. These are the
     * authorization ORACLE; the full 792-request sweep stays a gate-comparison characterization.
     */
    private const INDEPENDENT_EXPECTATIONS = [
        // Viewing the Empresas list requires the affiliate view permission; staff hold it; others are denied.
        'EmpresaResource:index' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'allow', 'affiliate_beta' => 'allow',
            'staff_linked_alfa' => 'allow', 'super_admin' => 'deny', 'no_permission' => 'deny',
            'revoked_alfa' => 'allow', 'role_manager' => 'deny',
        ],
        // Editing a company: only its own verified affiliate; every other actor (incl. staff and super admin) is denied.
        'EmpresaResource:edit:alfa' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'allow', 'affiliate_beta' => 'deny',
            'staff_linked_alfa' => 'deny', 'super_admin' => 'deny', 'no_permission' => 'deny',
            'revoked_alfa' => 'deny', 'role_manager' => 'deny',
        ],
        'EmpresaResource:edit:beta' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'deny', 'affiliate_beta' => 'allow',
            'staff_linked_alfa' => 'deny', 'super_admin' => 'deny', 'no_permission' => 'deny',
            'revoked_alfa' => 'deny', 'role_manager' => 'deny',
        ],
        // The user and role admin lists: only the staff actor (and, for roles, the role manager) may see them.
        'UserResource:index' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'deny', 'affiliate_beta' => 'deny',
            'staff_linked_alfa' => 'allow', 'super_admin' => 'deny', 'no_permission' => 'deny',
            'revoked_alfa' => 'deny', 'role_manager' => 'deny',
        ],
        'RoleResource:index' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'deny', 'affiliate_beta' => 'deny',
            'staff_linked_alfa' => 'allow', 'super_admin' => 'deny', 'no_permission' => 'deny',
            'revoked_alfa' => 'deny', 'role_manager' => 'allow',
        ],
        // The manual and the dashboard are open to any verified panel user.
        'UserManual' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'allow', 'affiliate_beta' => 'allow',
            'staff_linked_alfa' => 'allow', 'super_admin' => 'allow', 'no_permission' => 'allow',
            'revoked_alfa' => 'allow', 'role_manager' => 'allow',
        ],
        'Dashboard' => [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate_alfa' => 'allow', 'affiliate_beta' => 'allow',
            'staff_linked_alfa' => 'allow', 'super_admin' => 'allow', 'no_permission' => 'allow',
            'revoked_alfa' => 'allow', 'role_manager' => 'allow',
        ],
    ];

    /** The one narrowly-identified reliability defect (backlog), counted not swallowed. Format: "label|actor". */
    private const KNOWN_RELIABILITY_FAILURES = [
        'JoinViews|staff_linked_alfa',
    ];

    /** @var array<string, ?User> */
    private array $actors = [];

    private Empresa $alfa;

    private Empresa $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (glob(base_path('tests/Fixtures/pgsql_report_views/*.php')) as $file) {
            (require $file)->up();
        }
        // The company form needs at least one city (EditEmpresa/CreateEmpresa::getSteps() read a loop variable; with no
        // cities it is undefined: a known reliability defect, registered by SEC-01, not a security control).
        $country = DB::table('countries')->insertGetId(['country_name' => 'ZZZPAIS', 'created_at' => now(), 'updated_at' => now()]);
        $state = DB::table('states')->insertGetId(['country_id' => $country, 'state_name' => 'ZZZESTADO', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insert(['country_id' => $country, 'states_id' => $state, 'city_name' => 'ZZZCIUDAD', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ fixtures

    private function user(array $permissions = [], bool $verified = true, ?string $role = null): User
    {
        $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
        if ($role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        if ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(string $marker, User $owner): Empresa
    {
        $sector = DB::table('sectors')->insertGetId(['name' => "ZZZSEC{$marker}", 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner);
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => "ZZZ{$marker}EMPRESA", 'phone' => '+58-212-5550000',
            'sector_principal_id' => $sector, 'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();

        return $empresa;
    }

    private function link(Empresa $empresa, User $user): void
    {
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function contact(Empresa $empresa, string $marker): Contact
    {
        $contact = Contact::create(['name' => "ZZZ{$marker}CONTACTO", 'email' => strtolower($marker).'@zzz.test']);
        DB::table('contact_empresa')->insert(['contact_id' => $contact->id, 'empresa_id' => $empresa->id, 'is_principal' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $contact;
    }

    private function actors(): void
    {
        $staff = [];
        foreach (array_keys(FilamentShield::getResources() ?? []) as $identifier) {
            $staff[] = "view_any_{$identifier}";
            $staff[] = "view_{$identifier}";
        }
        foreach (array_keys(FilamentShield::getPages() ?? []) as $pagePermission) {
            $staff[] = $pagePermission;
        }

        $this->actors = [
            'guest' => null,
            'unverified' => $this->user(self::AFFILIATE, verified: false),
            'affiliate_alfa' => $alfaUser = $this->user(self::AFFILIATE),
            'affiliate_beta' => $betaUser = $this->user(self::AFFILIATE),
            'staff_linked_alfa' => $this->user(array_values(array_unique($staff))),
            'super_admin' => $this->user(role: config('filament-shield.super_admin.name')),
            'no_permission' => $this->user(),
            'revoked_alfa' => $this->user(self::AFFILIATE),
            'role_manager' => $this->user(['view_any_role', 'view_role', 'update_role']),
        ];
        $this->alfa = $this->company('ALFA', $alfaUser);
        $this->beta = $this->company('BETA', $betaUser);
        $this->link($this->alfa, $this->actors['unverified']);
        $this->link($this->alfa, $this->actors['staff_linked_alfa']);
        $this->link($this->alfa, $this->actors['super_admin']);
        $this->link($this->alfa, $this->actors['revoked_alfa']);
        DB::table('empresa_user')->where('empresa_id', $this->alfa->id)->where('user_id', $this->actors['revoked_alfa']->id)->delete();
    }

    /** @return list<array{label: string, url: string, kind: string, target: class-string, record: ?Model}> */
    private function entrypoints(): array
    {
        $panel = Filament::getPanel('admin');
        $list = [];
        foreach ($panel->getResources() as $resource) {
            $pages = $resource::getPages();
            $records = match ($resource) {
                EmpresaResource::class => ['alfa' => $this->alfa, 'beta' => $this->beta],
                \App\Filament\Resources\UserResource::class => ['other_user' => $this->actors['affiliate_beta']],
                default => array_filter(['first' => $this->firstRecord($resource::getModel())]),
            };
            foreach ($pages as $name => $page) {
                if (in_array($name, ['index', 'create'], true)) {
                    $list[] = ['label' => class_basename($resource).":{$name}", 'url' => $resource::getUrl($name), 'kind' => $name, 'target' => $resource, 'record' => null];
                } elseif (in_array($name, ['edit', 'view'], true)) {
                    foreach ($records as $tag => $record) {
                        $list[] = ['label' => class_basename($resource).":{$name}:{$tag}", 'url' => $resource::getUrl($name, ['record' => $record]), 'kind' => $name, 'target' => $resource, 'record' => $record];
                    }
                }
            }
        }
        foreach ($panel->getPages() as $page) {
            try {
                $url = $page::getUrl();
            } catch (Throwable) {
                continue; // a page that needs route parameters is reached from its resource
            }
            $list[] = ['label' => class_basename($page), 'url' => $url, 'kind' => 'page', 'target' => $page, 'record' => null];
        }

        return $list;
    }

    private function firstRecord(string $model): ?Model
    {
        try {
            return $model::query()->orderBy((new $model)->getKeyName())->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function outcome(?User $actor, string $url): string
    {
        auth()->logout();
        $this->flushSession();
        if ($actor) {
            $this->actingAs($actor);
        }
        try {
            $r = $this->get($url);
        } catch (Throwable $e) {
            return 'exception:'.class_basename($e);
        }
        $status = $r->getStatusCode();
        if (in_array($status, [301, 302, 303], true)) {
            $to = (string) $r->headers->get('Location');

            return match (true) {
                str_contains($to, '/admin/login') => 'login',
                str_contains($to, 'email-verification') => 'verify',
                default => 'redirect',
            };
        }

        return match ($status) {
            200 => 'ok',
            403 => 'forbidden',
            404 => 'not_found',
            default => "http_{$status}",
        };
    }

    /** The server-side gate for this entrypoint, evaluated as the actor. */
    private function gate(?User $actor, array $entry): bool
    {
        if (! $actor) {
            return false;
        }
        $this->actingAs($actor);
        $target = $entry['target'];

        return match ($entry['kind']) {
            'index' => $target::canViewAny(),
            'create' => $target::canCreate(),
            'edit' => $target::canEdit($entry['record']),
            'view' => $target::canView($entry['record']),
            'page' => $target::canAccess(),
        };
    }

    // ------------------------------------------------------------------ the HTTP matrix

    #[Test]
    public function every_actor_reaches_only_what_its_server_side_gate_allows(): void
    {
        $this->actors();
        $entries = $this->entrypoints();
        $this->assertGreaterThan(60, count($entries));

        $matrix = [];
        $violations = [];
        $unexpectedErrors = [];
        foreach ($this->actors as $name => $actor) {
            foreach ($entries as $entry) {
                $outcome = $this->outcome($actor, $entry['url']);
                $matrix[$entry['label']][$name] = $outcome;
                $isEmpresaRecord = $entry['target'] === EmpresaResource::class && $entry['record'] !== null;

                // R2-REV-03: an unexpected 5xx/exception is a FAILURE, except the one narrowly-identified, counted
                // JoinViews reliability defect (backlog); it is not swallowed as a security pass.
                if (str_starts_with($outcome, 'exception') || str_starts_with($outcome, 'http_5')) {
                    $matrix[$entry['label']]['_error'][] = $name;
                    if (! in_array("{$entry['label']}|{$name}", self::KNOWN_RELIABILITY_FAILURES, true)) {
                        $unexpectedErrors[] = "{$entry['label']} as {$name}: {$outcome}";
                    }
                    continue;
                }

                $expected = match (true) {
                    $name === 'guest' => $outcome === 'login',
                    $name === 'unverified' => $outcome === 'verify',
                    $outcome === 'ok' => $this->gate($actor, $entry) && (! $isEmpresaRecord || $entry['record']->users()->whereKey($actor->id)->exists()),
                    default => true,
                };
                if ($name === 'no_permission' && $entry['kind'] !== 'page' && $outcome === 'ok') {
                    $expected = false;
                }
                if (! $expected) {
                    $violations[] = "{$entry['label']} as {$name}: {$outcome}";
                }
            }
        }

        // R2-REV-03: INDEPENDENT expectations for high-value entrypoints — literal approved rules, not the gate under
        // test. These rows are an authorization oracle; the full 792-request sweep above stays a gate-comparison
        // characterization (a wrongly permissive gate would satisfy both sides of its own comparison).
        $independentViolations = [];
        foreach (self::INDEPENDENT_EXPECTATIONS as $label => $perActor) {
            foreach ($perActor as $actor => $want) {
                $got = $matrix[$label][$actor] ?? 'missing';
                $ok = match ($want) {
                    'allow' => $got === 'ok',
                    'deny' => in_array($got, ['forbidden', 'not_found'], true),
                    default => $got === $want, // 'login' | 'verify'
                };
                if (! $ok) {
                    $independentViolations[] = "{$label} as {$actor}: expected {$want}, got {$got}";
                }
            }
        }

        // Company scope, explicitly (also for staff and super_admin: no automatic cross-company access in the resource).
        foreach (['affiliate_alfa' => ['ok', 'not_found'], 'affiliate_beta' => ['not_found', 'ok'], 'staff_linked_alfa' => [null, 'not_found'],
            'revoked_alfa' => ['not_found', 'not_found'], 'super_admin' => [null, 'not_found']] as $actor => [$alfa, $beta]) {
            if ($alfa) {
                $this->assertSame($alfa, $matrix['EmpresaResource:edit:alfa'][$actor], "{$actor} → ALFA");
            }
            $this->assertContains($matrix['EmpresaResource:edit:beta'][$actor], $beta === 'ok' ? ['ok'] : ['not_found', 'forbidden'], "{$actor} → BETA");
        }

        $dir = storage_path('app/pdf_evidence');
        @mkdir($dir, 0775, true);
        ksort($matrix);
        file_put_contents("{$dir}/sec01_access_matrix.json", json_encode([
            'actors' => array_keys($this->actors),
            'entrypoints' => count($entries),
            'requests' => count($entries) * count($this->actors),
            'outcomes' => array_count_values(array_merge(...array_map(fn ($row) => array_values(array_filter($row, 'is_string')), array_values($matrix)))),
            'violations' => $violations,
            'independent_violations' => $independentViolations,
            'unexpected_errors' => $unexpectedErrors,
            'known_reliability_failures' => self::KNOWN_RELIABILITY_FAILURES,
            'oracle' => 'gate-comparison for the full sweep; INDEPENDENT_EXPECTATIONS rows are literal business rules',
            'matrix' => $matrix,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->assertSame([], $independentViolations, 'high-value entrypoints match the literal approved rules');
        $this->assertSame([], $unexpectedErrors, 'no unexpected 5xx/exception (only the counted JoinViews defect is allowed)');
        $this->assertSame([], $violations, 'every 200 matches the server-side gate; guests and unverified users never get in');
    }

    // ------------------------------------------------------------------ Livewire paths a browser can drive

    #[Test]
    public function list_and_global_search_show_only_the_linked_company(): void
    {
        $this->actors();
        Livewire::actingAs($this->actors['affiliate_alfa'])->test(ListEmpresas::class)->call('loadTable')
            ->assertCanSeeTableRecords([$this->alfa])->assertCanNotSeeTableRecords([$this->beta]);
        Livewire::actingAs($this->actors['revoked_alfa'])->test(ListEmpresas::class)->call('loadTable')
            ->assertCanNotSeeTableRecords([$this->alfa, $this->beta]);

        $this->actingAs($this->actors['affiliate_alfa']);
        $results = Filament::getGlobalSearchProvider()->getResults('ZZZ');
        $titles = collect($results?->getCategories() ?? [])->flatten(1)->map(fn ($r) => (string) $r->title)->all();
        $this->assertContains('ZZZALFAEMPRESA', $titles, 'allowed: the own company is found');
        $this->assertNotContains('ZZZBETAEMPRESA', $titles, 'denied: another company is not found');
    }

    #[Test]
    public function edit_saves_allowed_fields_and_ignores_forged_protected_fields(): void
    {
        $this->actors();
        $before = $this->alfa->fresh();
        $otherSector = DB::table('sectors')->insertGetId(['name' => 'ZZZSECFORJADO', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::actingAs($this->actors['affiliate_alfa'])->test(EditEmpresa::class, ['record' => $this->alfa->getRouteKey()])
            ->set('data.name', 'ZZZALFA RENOMBRADA')
            ->set('data.rif', 'J000000001')
            ->set('data.sector_principal_id', $otherSector)
            ->set('data.id', $this->beta->id)
            ->set('data.completion_percentage', 100)
            ->call('save');

        $after = $this->alfa->fresh();
        $this->assertSame('ZZZALFA RENOMBRADA', $after->name, 'allowed: the affiliate edits its company');
        $this->assertSame($before->rif, $after->rif, 'protected: only a super admin changes the RIF');
        $this->assertSame($before->sector_principal_id, $after->sector_principal_id, 'protected: only a super admin changes the principal sector');
        $this->assertSame('ZZZBETAEMPRESA', $this->beta->fresh()->name, 'a forged id does not reach another company');
        $this->assertSame($before->completion_percentage, $after->completion_percentage, 'internal field not mass-assignable');
    }

    #[Test]
    public function a_mixed_company_bulk_selection_only_acts_on_the_linked_company(): void
    {
        $this->actors();
        $alfa2 = $this->company('ALFADOS', $this->actors['affiliate_alfa']);

        // The list's bulk actions («Editar», «Exportar listado») act on the records Filament resolves from the selection,
        // through the resource query (scoped to the user's companies). A forged selection with another company's id:
        $list = Livewire::actingAs($this->actors['affiliate_alfa'])->test(ListEmpresas::class)->call('loadTable');
        $list->set('selectedTableRecords', [(string) $alfa2->getKey(), (string) $this->beta->getKey()]);
        $resolved = array_map('intval', $list->instance()->getSelectedTableRecords()->modelKeys());
        sort($resolved);
        $this->assertSame([$alfa2->id], $resolved, 'only the linked company of the mixed selection is resolved');

        // «Editar» with only the other company selected does not open it.
        $list->set('selectedTableRecords', [(string) $this->beta->getKey()]);
        $this->assertCount(0, $list->instance()->getSelectedTableRecords());
    }

    #[Test]
    public function relation_manager_actions_cannot_reach_another_companys_records(): void
    {
        $this->actors();
        $alfaContact = $this->contact($this->alfa, 'ALFA');
        $betaContact = $this->contact($this->beta, 'BETA');
        $chamber = DB::table('chambers')->insertGetId(['name' => 'ZZZCAMARA', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$this->alfa, $this->beta] as $empresa) {
            DB::table('chamber_empresa')->insert(['empresa_id' => $empresa->id, 'chamber_id' => $chamber, 'created_at' => now(), 'updated_at' => now()]);
        }
        $onAlfa = fn (string $manager) => Livewire::actingAs($this->actors['affiliate_alfa'])
            ->test($manager, ['ownerRecord' => $this->alfa, 'pageClass' => EditEmpresa::class])->call('loadTable');
        $attempt = function (callable $call) {
            try {
                $call();
            } catch (Throwable) {
                // not found / not mounted: a denial; the database is checked below
            }
        };

        // Another company's records do not appear and cannot be acted on from ALFA's page.
        $onAlfa(ContactsRelationManager::class)->assertCanSeeTableRecords([$alfaContact])->assertCanNotSeeTableRecords([$betaContact]);
        // Keys, as a browser would send them: Filament resolves each one through the relation manager's own query.
        $attempt(fn () => $onAlfa(ContactsRelationManager::class)->callTableAction('detach', $betaContact->getKey()));
        $attempt(fn () => $onAlfa(ContactsRelationManager::class)->callTableAction('marcar_principal', $betaContact->getKey()));
        $attempt(fn () => $onAlfa(UsersRelationManager::class)->callTableAction('detach', $this->actors['affiliate_beta']->getKey()));
        $this->assertTrue(DB::table('contact_empresa')->where(['empresa_id' => $this->beta->id, 'contact_id' => $betaContact->id, 'is_principal' => false])->exists());
        $this->assertTrue(DB::table('empresa_user')->where(['empresa_id' => $this->beta->id, 'user_id' => $this->actors['affiliate_beta']->id])->exists());

        // Allowed: ALFA's own records. Detaching the shared chamber from ALFA leaves BETA's link alone.
        $onAlfa(ContactsRelationManager::class)->callTableAction('marcar_principal', $alfaContact->getKey());
        $this->assertTrue(DB::table('contact_empresa')->where(['empresa_id' => $this->alfa->id, 'contact_id' => $alfaContact->id, 'is_principal' => true])->exists());
        $onAlfa(ChambersRelationManager::class)->callTableAction('detach', $chamber);
        $this->assertFalse(DB::table('chamber_empresa')->where(['empresa_id' => $this->alfa->id, 'chamber_id' => $chamber])->exists());
        $this->assertTrue(DB::table('chamber_empresa')->where(['empresa_id' => $this->beta->id, 'chamber_id' => $chamber])->exists());

        // Mixed bulk export on ALFA's contacts: BETA's contact is not part of the selection Filament resolves.
        $rm = $onAlfa(ContactsRelationManager::class);
        $rm->set('selectedTableRecords', [(string) $alfaContact->id, (string) $betaContact->id]);
        $resolved = $rm->instance()->getSelectedTableRecords()->modelKeys();
        $this->assertSame([$alfaContact->id], array_map('intval', $resolved));
    }

    #[Test]
    public function a_revoked_or_unlinked_user_cannot_mount_the_companys_edit_page(): void
    {
        $this->actors();
        foreach (['revoked_alfa', 'affiliate_beta', 'no_permission'] as $actor) {
            try {
                Livewire::actingAs($this->actors[$actor])->test(EditEmpresa::class, ['record' => $this->alfa->getRouteKey()]);
                $this->fail("{$actor} mounted ALFA's edit page");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('ZZZALFAEMPRESA', $this->alfa->fresh()->name);
    }

    #[Test]
    public function create_links_the_new_company_to_its_creator_only(): void
    {
        $this->actors();
        // The affiliate role has no create_empresa (the matrix shows its 403); a holder of that permission creates through
        // the panel page. Forged keys outside the form (id, users, completion) are not taken.
        $creator = $this->user([...self::AFFILIATE, 'create_empresa']);
        $sector = DB::table('sectors')->insertGetId(['name' => 'ZZZSECNUEVA', 'created_at' => now(), 'updated_at' => now()]);
        Livewire::actingAs($creator)->test(\App\Filament\Resources\EmpresaResource\Pages\CreateEmpresa::class)
            ->set('data.rif', 'J'.random_int(100000000, 999999999))
            ->set('data.name', 'ZZZNUEVA')
            ->set('data.sector_principal_id', $sector)
            ->set('data.status_id', 1)
            ->set('data.id', $this->beta->id)
            ->set('data.users', [$this->actors['affiliate_beta']->id])
            ->set('data.completion_percentage', 100)
            ->call('create')
            ->assertHasNoFormErrors();

        $empresa = Empresa::where('name', 'ZZZNUEVA')->firstOrFail();
        $this->assertNotSame($this->beta->id, $empresa->id, 'the id is not taken from input');
        $this->assertSame([$creator->id], DB::table('empresa_user')->where('empresa_id', $empresa->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all(), 'allowed: linked to its creator, and only to them');
        $this->assertSame('ZZZBETAEMPRESA', $this->beta->fresh()->name);
    }

    /**
     * SEC-01 F-01: impersonation («Impersonate» in Usuarios) is a super-admin capability. Seeing the user list must not let
     * anyone else take another account's session. Allowed path: a super admin impersonates an affiliate and leaves.
     */
    #[Test]
    public function only_a_super_admin_can_impersonate_and_never_another_super_admin_by_a_lesser_role(): void
    {
        $this->actors();
        $manager = app(\Lab404\Impersonate\Services\ImpersonateManager::class);
        $viewer = $this->user(['view_any_user', 'view_user']);

        $list = Livewire::actingAs($viewer)->test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)->call('loadTable');
        foreach ([$this->actors['super_admin'], $this->actors['affiliate_alfa']] as $target) {
            try {
                $list->callTableAction('impersonate', $target);
            } catch (Throwable) {
                // a refused action is a denial; the session is checked below
            }
            $this->assertFalse($manager->isImpersonating(), 'denied: a user-list viewer does not impersonate');
            $this->assertSame($viewer->id, auth()->id(), 'denied: the session still belongs to the viewer');
        }
        $list->assertTableActionHidden('impersonate', $this->actors['super_admin']);

        // Allowed: the super admin keeps the action, and impersonating then leaving works. (Running the action inside the
        // Livewire test would switch the session in the middle of the test request; the gate is the same method.)
        $super = $this->actors['super_admin'];
        $this->assertTrue($super->canImpersonate());
        $this->assertFalse($viewer->canImpersonate());
        $this->actingAs($super);
        $manager->take($super, $this->actors['affiliate_alfa']);
        $this->assertSame($this->actors['affiliate_alfa']->id, auth()->id());
        $manager->leave();
        $this->assertSame($super->id, auth()->id());
    }
}

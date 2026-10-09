<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TaxonomySelectionSettingsPage;
use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Mail\TaxonomyCategoryRequestMail;
use App\Models\Empresa;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyCategoryRequest;
use App\Models\TaxonomyCategoryTranslation;
use App\Models\TaxonomySelectionSettings;
use App\Models\User;
use App\Services\TaxonomyCategoryRequestService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TASK-0010A (Issue #2 comentario `6079967780`): solicitud de revisión de categoría CPV.
 *
 * DDL transaccional: la base de tests es la Postgres remota compartida (la misma de staging) y esta
 * rama NO puede correr `migrate` sola contra ella. Por eso setUp() aplica la migración nueva DENTRO
 * de la transacción de `DatabaseTransactions` (DDL en Postgres es transaccional) - el rollback al
 * final de cada test la deshace por completo, sin dejar ni la tabla ni la columna nueva en la base
 * compartida. El `up()` de la migración es idempotente (`IF NOT EXISTS`), así que el mismo test
 * sigue funcionando cuando la migración ya esté aplicada de verdad (deploy integrado).
 */
class TaxonomyCategoryRequestTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MIGRATION = '2026_10_09_120000_create_taxonomy_category_requests_table.php';

    private const DATOS_VALIDOS = [
        'necesidad' => 'Reparación de bombas electrosumergibles en sitio',
        'justificacion' => 'CAPACIDAD_ESPECIALIZADA',
        'detalle' => 'Hacemos reparación en campo de bombas BES marca X, con banco de pruebas móvil.',
        'terminos_probados' => 'BES, bombas electrosumergibles, ESP',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('pgsql');
        if (! $schema->hasTable('taxonomy_category_requests') || ! $schema->hasColumn('taxonomy_selection_settings', 'category_request_recipient_email')) {
            (require database_path('migrations/'.self::MIGRATION))->up();
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{0: Empresa, 1: User} Mismo patrón que TaxonomyCategoriesRelationManagerTest. */
    private function empresaConUsuario(): array
    {
        $user = User::factory()->create(['phone' => '0414-5550000']);
        $this->actingAs($user);

        $empresa = Empresa::create([
            'rif' => 'J'.random_int(10000000, 99999999).'-'.random_int(0, 9),
            'name' => 'zzz_test_empresa_'.uniqid('', true),
            'phone' => '0212-5551234',
            'website' => 'https://ejemplo.test',
        ]);

        return [$empresa, $user];
    }

    private function categoriaHojaConNombre(string $nombre): TaxonomyCategory
    {
        $grupo = TaxonomyCategory::create(['code' => 'CPV-'.random_int(900, 999), 'parent_id' => null, 'is_active' => true, 'source_version' => 'test']);
        $familia = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(10, 98), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);
        $hoja = TaxonomyCategory::create(['code' => $familia->code.'.'.random_int(10, 98).'G', 'parent_id' => $familia->id, 'is_active' => true, 'source_version' => 'test']);
        TaxonomyCategoryTranslation::create(['category_id' => $hoja->id, 'locale' => 'es', 'name' => $nombre]);

        return $hoja;
    }

    private function configurarDestinatario(?string $email): void
    {
        TaxonomySelectionSettings::current()->update(['category_request_recipient_email' => $email]);
    }

    private function relationManager(Empresa $empresa, User $user)
    {
        return Livewire::actingAs($user)->test(TaxonomyCategoriesRelationManager::class, [
            'ownerRecord' => $empresa,
            'pageClass' => EditEmpresa::class,
        ]);
    }

    // 1
    #[Test]
    public function the_request_action_and_its_form_exist_in_the_categories_cpv_relation_manager(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();

        $this->relationManager($empresa, $user)
            ->assertTableActionExists('solicitarRevisionCategoria')
            ->assertTableActionVisible('solicitarRevisionCategoria')
            ->assertTableActionHasLabel('solicitarRevisionCategoria', 'No encuentro la categoría / Solicitar revisión')
            ->mountTableAction('solicitarRevisionCategoria')
            ->assertFormFieldExists('necesidad', 'mountedTableActionForm')
            ->assertFormFieldExists('justificacion', 'mountedTableActionForm')
            ->assertFormFieldExists('detalle', 'mountedTableActionForm')
            ->assertFormFieldExists('terminos_probados', 'mountedTableActionForm');

        $this->assertStringContainsString('NO crea una categoría', TaxonomyCategoriesRelationManager::SOLICITUD_MODAL_DESCRIPTION);
        $this->assertStringContainsString('podrá contactarle', TaxonomyCategoriesRelationManager::SOLICITUD_MODAL_DESCRIPTION);
    }

    // 2
    #[Test]
    public function an_authenticated_company_user_can_submit_for_its_accessible_company(): void
    {
        Mail::fake();
        $this->configurarDestinatario('camara-categorias@ejemplo.test');
        [$empresa, $user] = $this->empresaConUsuario();

        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: self::DATOS_VALIDOS)
            ->assertHasNoTableActionErrors()
            ->assertNotified(TaxonomyCategoriesRelationManager::SOLICITUD_OK_TITLE);

        $solicitud = TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->sole();
        $this->assertSame($user->id, $solicitud->user_id);
        $this->assertSame(self::DATOS_VALIDOS['necesidad'], $solicitud->necesidad);
        $this->assertSame('CAPACIDAD_ESPECIALIZADA', $solicitud->justificacion);
        $this->assertSame(self::DATOS_VALIDOS['terminos_probados'], $solicitud->terminos_probados);
        $this->assertSame(TaxonomyCategoryRequest::STATUS_PENDING_REVIEW, $solicitud->request_status);
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SENT, $solicitud->delivery_status);
        $this->assertSame('camara-categorias@ejemplo.test', $solicitud->recipient_email);
        $this->assertNotNull($solicitud->submitted_at);
        $this->assertNotNull($solicitud->mail_sent_at);
    }

    // 3
    #[Test]
    public function persisted_empresa_and_user_ids_cannot_be_spoofed(): void
    {
        Mail::fake();
        $this->configurarDestinatario('camara-categorias@ejemplo.test');
        [$otraEmpresa, $otroUsuario] = $this->empresaConUsuario();
        [$empresa, $user] = $this->empresaConUsuario();

        // a) Claves inyectadas en los datos del formulario se ignoran.
        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: self::DATOS_VALIDOS + [
                'empresa_id' => $otraEmpresa->id,
                'user_id' => $otroUsuario->id,
            ]);

        $solicitud = TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->sole();
        $this->assertSame($user->id, $solicitud->user_id);
        $this->assertSame(0, TaxonomyCategoryRequest::query()->where('empresa_id', $otraEmpresa->id)->count());

        // b) El mismo bypass directo contra el servicio también se ignora.
        $solicitud2 = app(TaxonomyCategoryRequestService::class)->submit($empresa, $user, self::DATOS_VALIDOS + [
            'empresa_id' => $otraEmpresa->id,
            'user_id' => $otroUsuario->id,
        ]);
        $this->assertSame($empresa->id, $solicitud2->empresa_id);
        $this->assertSame($user->id, $solicitud2->user_id);

        // c) Un usuario sin acceso a la empresa no puede registrar nada para ella.
        try {
            app(TaxonomyCategoryRequestService::class)->submit($otraEmpresa, $user, self::DATOS_VALIDOS);
            $this->fail('Debió rechazar al usuario sin acceso a la empresa.');
        } catch (AuthorizationException) {
            // esperado
        }
        $this->assertSame(0, TaxonomyCategoryRequest::query()->where('empresa_id', $otraEmpresa->id)->count());
    }

    // 4
    #[Test]
    public function the_justification_list_is_closed_and_validated(): void
    {
        Mail::fake();
        [$empresa, $user] = $this->empresaConUsuario();

        $this->assertSame(
            ['NO_ENCUENTRO_CATEGORIA', 'DEMASIADO_GENERAL', 'OTRO_NOMBRE', 'NO_ESTOY_SEGURO', 'CAPACIDAD_ESPECIALIZADA', 'OTRO'],
            array_keys(TaxonomyCategoryRequest::JUSTIFICACIONES),
        );

        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: ['justificacion' => 'CREAR_CATEGORIA_YA'] + self::DATOS_VALIDOS)
            ->assertHasTableActionErrors(['justificacion']);

        try {
            app(TaxonomyCategoryRequestService::class)->submit($empresa, $user, ['justificacion' => 'CREAR_CATEGORIA_YA'] + self::DATOS_VALIDOS);
            $this->fail('El servicio debió rechazar una justificación fuera de la lista.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('justificacion', $e->errors());
        }

        $this->assertSame(0, TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->count());
    }

    // 5
    #[Test]
    public function required_fields_reject_an_empty_submission(): void
    {
        Mail::fake();
        [$empresa, $user] = $this->empresaConUsuario();

        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: [
                'necesidad' => '',
                'justificacion' => null,
                'detalle' => '',
                'terminos_probados' => '',
            ])
            ->assertHasTableActionErrors(['necesidad' => 'required', 'justificacion' => 'required', 'detalle' => 'required']);

        // OTRO exige explicar el motivo: un detalle corto no alcanza.
        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: ['justificacion' => 'OTRO', 'detalle' => 'Porque sí, otro motivo.'] + self::DATOS_VALIDOS)
            ->assertHasTableActionErrors(['detalle']);

        // Largo máximo validado.
        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: ['detalle' => str_repeat('a', TaxonomyCategoryRequestService::DETALLE_MAX + 1)] + self::DATOS_VALIDOS)
            ->assertHasTableActionErrors(['detalle']);

        $this->assertSame(0, TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->count());
        Mail::assertNothingSent();
    }

    // 6
    #[Test]
    public function the_request_is_persisted_before_and_independently_of_the_mail_attempt(): void
    {
        $this->configurarDestinatario('camara-categorias@ejemplo.test');
        [$empresa, $user] = $this->empresaConUsuario();

        $filaAlMomentoDelEnvio = null;
        Mail::shouldReceive('to')->once()->andReturnUsing(function () use ($empresa, &$filaAlMomentoDelEnvio) {
            $filaAlMomentoDelEnvio = TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->first();

            return new class
            {
                public function send($mailable): void
                {
                }
            };
        });

        $solicitud = app(TaxonomyCategoryRequestService::class)->submit($empresa, $user, self::DATOS_VALIDOS);

        $this->assertNotNull($filaAlMomentoDelEnvio, 'La solicitud debe existir en la base ANTES de intentar el correo.');
        $this->assertSame($solicitud->id, $filaAlMomentoDelEnvio->id);
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_PENDING, $filaAlMomentoDelEnvio->delivery_status);
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SENT, $solicitud->delivery_status);
    }

    // 7
    #[Test]
    public function the_mail_goes_to_the_configured_recipient_with_subject_and_key_content(): void
    {
        Mail::fake();
        $this->configurarDestinatario('camara-categorias@ejemplo.test');
        [$empresa, $user] = $this->empresaConUsuario();
        $hoja = $this->categoriaHojaConNombre('zzz Categoría vinculada de prueba');
        $empresa->taxonomyCategories()->create(['category_id' => $hoja->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, 'es_principal' => true]);

        $solicitud = app(TaxonomyCategoryRequestService::class)->submit($empresa, $user, [
            'necesidad' => 'Servicio <script>alert(1)</script> de BES',
        ] + self::DATOS_VALIDOS);

        Mail::assertSent(TaxonomyCategoryRequestMail::class, function (TaxonomyCategoryRequestMail $mail) use ($empresa, $user, $solicitud) {
            $this->assertTrue($mail->hasTo('camara-categorias@ejemplo.test'));
            $this->assertSame("Solicitud de revisión de categoría CPV — {$empresa->name} — #{$solicitud->id}", $mail->envelope()->subject);

            $html = $mail->render();
            foreach ([
                "#{$solicitud->id}",
                e($empresa->name),
                e($empresa->rif),
                '0212-5551234',
                'https://ejemplo.test',
                e($user->name),
                e($user->email),
                '0414-5550000',
                e(TaxonomyCategoryRequest::JUSTIFICACIONES['CAPACIDAD_ESPECIALIZADA']),
                e(self::DATOS_VALIDOS['detalle']),
                e(self::DATOS_VALIDOS['terminos_probados']),
                'zzz Categoría vinculada de prueba',
                '(Principal)',
                TaxonomyCategoryRequestMail::NOTA_NO_CREACION,
                "/empresas/{$empresa->id}/edit",
            ] as $esperado) {
                $this->assertStringContainsString($esperado, $html, "Falta en el correo: {$esperado}");
            }

            // Escapado: el texto del usuario nunca se inyecta como HTML.
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);

            return true;
        });
    }

    // 8
    #[Test]
    public function a_missing_recipient_configuration_does_not_lose_the_request(): void
    {
        Mail::fake();
        Log::spy();
        $this->configurarDestinatario(null);
        [$empresa, $user] = $this->empresaConUsuario();

        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: self::DATOS_VALIDOS)
            ->assertHasNoTableActionErrors()
            ->assertNotified(TaxonomyCategoriesRelationManager::SOLICITUD_WARNING_TITLE);

        $solicitud = TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->sole();
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_FAILED, $solicitud->delivery_status);
        $this->assertSame(TaxonomyCategoryRequestService::ERROR_SIN_DESTINATARIO, $solicitud->delivery_error);
        $this->assertNull($solicitud->recipient_email);
        $this->assertNull($solicitud->mail_sent_at);
        $this->assertSame(TaxonomyCategoryRequest::STATUS_PENDING_REVIEW, $solicitud->request_status);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto = []) => str_contains($mensaje, 'TASK-0010A')
            && ($contexto['taxonomy_category_request_id'] ?? null) === $solicitud->id)->once();
    }

    // 9
    #[Test]
    public function a_mail_exception_does_not_lose_the_request_and_shows_a_user_safe_status(): void
    {
        Log::spy();
        $this->configurarDestinatario('camara-categorias@ejemplo.test');
        [$empresa, $user] = $this->empresaConUsuario();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP 535 detalle-interno-secreto'));

        $this->relationManager($empresa, $user)
            ->callTableAction('solicitarRevisionCategoria', data: self::DATOS_VALIDOS)
            ->assertHasNoTableActionErrors()
            ->assertNotified(TaxonomyCategoriesRelationManager::SOLICITUD_WARNING_TITLE);

        $solicitud = TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->sole();
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_FAILED, $solicitud->delivery_status);
        $this->assertSame(TaxonomyCategoryRequestService::ERROR_ENVIO, $solicitud->delivery_error);
        $this->assertSame('camara-categorias@ejemplo.test', $solicitud->recipient_email);
        $this->assertNull($solicitud->mail_sent_at);

        // El detalle técnico va SOLO al log del servidor - nunca a la notificación del usuario.
        $notificacion = TaxonomyCategoriesRelationManager::notificacionSolicitud($solicitud)->toArray();
        $this->assertStringNotContainsString('SMTP', json_encode($notificacion));
        $this->assertStringNotContainsString('detalle-interno-secreto', json_encode(session()->all()));
        Log::shouldHaveReceived('error')->withArgs(fn ($mensaje, $contexto = []) => ($contexto['message'] ?? null) === 'SMTP 535 detalle-interno-secreto')->once();
    }

    // 10
    #[Test]
    public function a_request_never_writes_taxonomy_categories_or_term_mappings(): void
    {
        Mail::fake();
        $this->configurarDestinatario('camara-categorias@ejemplo.test');
        [$empresa, $user] = $this->empresaConUsuario();

        $tablasProtegidas = [
            'taxonomy_categories', 'taxonomy_category_translations', 'taxonomy_category_synonyms',
            'taxonomy_term_cpv_relations', 'taxonomy_canonical_concepts', 'taxonomy_candidate_concept_links',
            'empresa_taxonomy_category',
        ];
        $contar = fn () => collect($tablasProtegidas)
            ->filter(fn ($t) => Schema::connection('pgsql')->hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => DB::connection('pgsql')->table($t)->count()])
            ->all();
        $antes = $contar();

        $escrituras = [];
        DB::connection('pgsql')->listen(function ($query) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $escrituras[] = $query->sql;
            }
        });

        app(TaxonomyCategoryRequestService::class)->submit($empresa, $user, self::DATOS_VALIDOS);

        $this->assertNotEmpty($escrituras);
        foreach ($escrituras as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(insert into|update)\s+"taxonomy_category_requests"/i', $sql, "Escritura inesperada: {$sql}");
        }
        $this->assertSame($antes, $contar());
    }

    // 11
    #[Test]
    public function the_existing_search_and_add_action_and_its_limits_remain_unchanged(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();
        $hoja1 = $this->categoriaHojaConNombre('zzz hoja uno');
        $hoja2 = $this->categoriaHojaConNombre('zzz hoja dos');
        TaxonomySelectionSettings::current()->update(['max_categorias_principales' => 1]);

        $rm = $this->relationManager($empresa, $user)
            ->assertTableActionExists('buscarYAgregar')
            ->assertTableActionHasLabel('buscarYAgregar', 'Buscar y agregar categoría')
            ->callTableAction('buscarYAgregar', data: ['categorias' => [$hoja1->id], 'familia_a_explorar' => null, 'categorias_de_familia' => [], 'tipo' => 'principal'])
            ->assertNotified('Categorías agregadas');

        $rm->callTableAction('buscarYAgregar', data: ['categorias' => [$hoja2->id], 'familia_a_explorar' => null, 'categorias_de_familia' => [], 'tipo' => 'principal'])
            ->assertNotified('Límite alcanzado');

        $this->assertSame(1, EmpresaTaxonomyCategory::query()->where('empresa_id', $empresa->id)->where('es_principal', true)->count());
        $this->assertSame(0, TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->count());
    }

    #[Test]
    public function super_admin_can_configure_a_validated_recipient_email_on_the_settings_page(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        $this->actingAs($admin);

        Livewire::actingAs($admin)->test(TaxonomySelectionSettingsPage::class)
            ->assertFormFieldExists('category_request_recipient_email')
            ->fillForm(['category_request_recipient_email' => 'no-es-un-correo'])
            ->call('save')
            ->assertHasFormErrors(['category_request_recipient_email' => 'email']);

        Livewire::actingAs($admin)->test(TaxonomySelectionSettingsPage::class)
            ->fillForm(['category_request_recipient_email' => 'responsable@ejemplo.test'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('responsable@ejemplo.test', TaxonomySelectionSettings::current()->category_request_recipient_email);

        $comun = User::factory()->create();
        $this->actingAs($comun);
        $this->assertFalse(TaxonomySelectionSettingsPage::canAccess());
    }
}

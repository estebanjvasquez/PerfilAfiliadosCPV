<?php

namespace Tests\Feature\Filament;

use App\Exceptions\CategoryRequestNoticeException;
use App\Filament\Pages\CategoryRequestHelpPage;
use App\Filament\Pages\CategoryRequestSettingsPage;
use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Filament\Resources\TaxonomyCategoryRequestResource;
use App\Filament\Resources\TaxonomyCategoryRequestResource\Pages\ListTaxonomyCategoryRequests;
use App\Filament\Resources\TaxonomyCategoryRequestResource\Pages\ViewTaxonomyCategoryRequest;
use App\Mail\TaxonomyCategoryRequestMail;
use App\Models\Empresa;
use App\Models\TaxonomyCategoryRequest;
use App\Models\TaxonomyCategoryRequestDeliveryAttempt;
use App\Models\TaxonomySelectionSettings;
use App\Models\User;
use App\Services\TaxonomyCategoryRequestService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TASK-0010C (Issue #2 comentario `6187768951`): operación de las solicitudes de revisión de
 * categoría - listado/detalle para Super Admin, configuración del destinatario, reintento manual
 * del aviso y transporte de correo honesto.
 *
 * Corre en el PostgreSQL DESECHABLE del entorno aislado, con datos sintéticos. Ningún correo sale:
 * todos los tests usan Mail::fake() o un transporte `log`/`array`. "smtp" aparece solo como NOMBRE
 * de transporte configurado para comprobar la rama "envía hacia afuera"; con Mail::fake no hay red.
 *
 * Mismo patrón de setUp que TaxonomyCategoryRequestTest: si el esquema no tiene las migraciones de
 * 0010A/0010C (una base ya existente), se aplican dentro de la transacción del test y se deshacen.
 */
class TaxonomyCategoryRequestOperationsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MIGRATION_A = '2026_10_09_120000_create_taxonomy_category_requests_table.php';
    private const MIGRATION_C = '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests.php';

    private const DATOS = [
        'necesidad' => 'Inspección de recipientes a presión con ultrasonido phased array',
        'justificacion' => 'CAPACIDAD_ESPECIALIZADA',
        'detalle' => 'Hacemos inspección PAUT en recipientes y tuberías de planta, con informe certificado.',
        'terminos_probados' => 'PAUT, ultrasonido, END',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('pgsql');
        if (! $schema->hasTable('taxonomy_category_requests') || ! $schema->hasColumn('taxonomy_selection_settings', 'category_request_recipient_email')) {
            (require database_path('migrations/'.self::MIGRATION_A))->up();
        }
        if (! $schema->hasTable('taxonomy_category_request_delivery_attempts')) {
            (require database_path('migrations/'.self::MIGRATION_C))->up();
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));

        return $admin;
    }

    /** @return array{0: Empresa, 1: User} afiliado con su empresa (Empresa::created lo vincula). */
    private function empresaConAfiliado(): array
    {
        $user = User::factory()->create(['phone' => '0414-5551111']);
        $this->actingAs($user);

        $empresa = Empresa::create([
            'rif' => 'Z'.substr(bin2hex(random_bytes(8)), 0, 11),
            'name' => 'zzz_ops_empresa_'.uniqid('', true),
            'phone' => '0212-5559999',
            'website' => 'https://ops.ejemplo.test',
        ]);

        return [$empresa, $user];
    }

    private function destinatario(?string $email): void
    {
        TaxonomySelectionSettings::current()->update(['category_request_recipient_email' => $email]);
    }

    /** Una solicitud enviada por el afiliado SIN destinatario: queda `failed` como la #1 de staging. */
    private function solicitudFallida(): array
    {
        $this->destinatario(null);
        [$empresa, $afiliado] = $this->empresaConAfiliado();
        Mail::fake();
        $solicitud = app(TaxonomyCategoryRequestService::class)->submit($empresa, $afiliado, self::DATOS);

        return [$solicitud, $empresa, $afiliado];
    }

    // ---------------------------------------------------------------------------------------
    // Acceso
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function super_admin_sees_an_existing_failed_request_in_the_list_and_its_full_detail(): void
    {
        [$solicitud, $empresa] = $this->solicitudFallida();
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_FAILED, $solicitud->delivery_status);
        $this->assertSame(TaxonomyCategoryRequest::ERROR_SIN_DESTINATARIO, $solicitud->delivery_error);

        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->assertTrue(TaxonomyCategoryRequestResource::canAccess());

        Livewire::actingAs($admin)->test(ListTaxonomyCategoryRequests::class)
            ->assertCanSeeTableRecords([$solicitud])
            ->assertSee('No hay correo responsable configurado');

        Livewire::actingAs($admin)->test(ViewTaxonomyCategoryRequest::class, ['record' => $solicitud->getKey()])
            ->assertSee("#{$solicitud->id}")
            ->assertSee(self::DATOS['necesidad'])
            ->assertSee(self::DATOS['detalle'])
            ->assertSee(self::DATOS['terminos_probados'])
            ->assertSee(TaxonomyCategoryRequest::JUSTIFICACIONES['CAPACIDAD_ESPECIALIZADA'])
            ->assertSee($empresa->name)
            ->assertSee(TaxonomyCategoryRequest::DELIVERY_LABELS[TaxonomyCategoryRequest::DELIVERY_FAILED])
            ->assertSee(TaxonomyCategoryRequest::ERROR_LABELS[TaxonomyCategoryRequest::ERROR_SIN_DESTINATARIO])
            // Explicación accionable con enlace a la configuración.
            ->assertSee('Configuración de solicitudes')
            ->assertSee(CategoryRequestSettingsPage::getUrl(), escape: false)
            // Historial: el intento inicial quedó registrado.
            ->assertSee(TaxonomyCategoryRequestDeliveryAttempt::KIND_LABELS[TaxonomyCategoryRequestDeliveryAttempt::KIND_INITIAL]);
    }

    #[Test]
    public function an_affiliate_cannot_reach_the_list_the_detail_the_settings_or_the_help_even_by_direct_url(): void
    {
        [$solicitud, , $afiliado] = $this->solicitudFallida();
        $this->actingAs($afiliado);

        $this->assertFalse(TaxonomyCategoryRequestResource::canAccess());
        $this->assertFalse($afiliado->can('view', $solicitud), 'Ni siquiera la solicitud de su propia empresa: el detalle es administrativo.');
        $this->assertFalse($afiliado->can('retryNotice', $solicitud));
        $this->assertFalse(CategoryRequestSettingsPage::canAccess());
        $this->assertFalse(CategoryRequestHelpPage::canAccess());

        foreach ([
            TaxonomyCategoryRequestResource::getUrl('index'),
            TaxonomyCategoryRequestResource::getUrl('view', ['record' => $solicitud->getKey()]),
            CategoryRequestSettingsPage::getUrl(),
            CategoryRequestHelpPage::getUrl(),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function nobody_can_create_edit_or_delete_requests_from_the_panel(): void
    {
        [$solicitud] = $this->solicitudFallida();
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->assertFalse(TaxonomyCategoryRequestResource::canCreate());
        $this->assertFalse($admin->can('update', $solicitud));
        $this->assertFalse($admin->can('delete', $solicitud));
        $this->assertFalse($admin->can('deleteAny', TaxonomyCategoryRequest::class));
        $this->assertSame(['index', 'view'], array_keys(TaxonomyCategoryRequestResource::getPages()));
    }

    // ---------------------------------------------------------------------------------------
    // Reintento
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function without_a_recipient_the_retry_is_disabled_and_the_service_refuses_without_recording(): void
    {
        [$solicitud] = $this->solicitudFallida();
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::actingAs($admin)->test(ListTaxonomyCategoryRequests::class)
            ->assertTableActionVisible('reintentarAviso', $solicitud)
            ->assertTableActionDisabled('reintentarAviso', $solicitud);

        try {
            app(TaxonomyCategoryRequestService::class)->retryNotice($solicitud, $admin);
            $this->fail('Sin destinatario el reintento debe rechazarse.');
        } catch (CategoryRequestNoticeException $e) {
            $this->assertSame(CategoryRequestNoticeException::RECIPIENT_MISSING, $e->reason);
        }

        $this->assertSame(1, $solicitud->deliveryAttempts()->count(), 'Solo el intento inicial: el rechazo no registra nada.');
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_retry_resends_the_original_request_without_creating_a_new_one_or_rewriting_it(): void
    {
        [$solicitud, $empresa, $afiliado] = $this->solicitudFallida();
        $antes = $solicitud->fresh()->only(['empresa_id', 'user_id', 'necesidad', 'justificacion', 'detalle', 'terminos_probados', 'context_snapshot', 'request_status', 'submitted_at']);
        $nombreAlEnviar = $empresa->name;

        // La empresa cambia de nombre DESPUÉS de enviar: el aviso usa el snapshot guardado.
        $empresa->update(['name' => 'zzz_nombre_posterior_'.uniqid()]);

        config(['mail.default' => 'smtp']);
        $this->destinatario('camara-categorias@ejemplo.test');
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $total = TaxonomyCategoryRequest::query()->count();

        Livewire::actingAs($admin)->test(ListTaxonomyCategoryRequests::class)
            ->assertTableActionEnabled('reintentarAviso', $solicitud)
            ->callTableAction('reintentarAviso', $solicitud)
            ->assertNotified('Aviso aceptado por el servidor de correo');

        Mail::assertSent(TaxonomyCategoryRequestMail::class, function (TaxonomyCategoryRequestMail $mail) use ($solicitud, $nombreAlEnviar) {
            $this->assertTrue($mail->hasTo('camara-categorias@ejemplo.test'));
            $this->assertSame($solicitud->id, $mail->categoryRequest->id);
            $html = $mail->render();
            $this->assertStringContainsString(e($nombreAlEnviar), $html);
            $this->assertStringContainsString(e(self::DATOS['detalle']), $html);

            return true;
        });
        Mail::assertSentCount(1);

        $this->assertSame($total, TaxonomyCategoryRequest::query()->count(), 'El reintento no crea otra solicitud.');
        $despues = $solicitud->fresh();
        $this->assertEquals($antes, $despues->only(array_keys($antes)), 'El contenido y el contexto originales no cambian.');
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SENT, $despues->delivery_status);
        $this->assertNull($despues->delivery_error);
        $this->assertNotNull($despues->mail_sent_at);
        $this->assertSame('smtp', $despues->delivery_transport);
        $this->assertNull($despues->delivery_claim_token);

        $intentos = $despues->deliveryAttempts()->get();
        $this->assertSame([1, 2], $intentos->pluck('attempt_number')->all());
        $this->assertSame([TaxonomyCategoryRequestDeliveryAttempt::KIND_INITIAL, TaxonomyCategoryRequestDeliveryAttempt::KIND_MANUAL_RETRY], $intentos->pluck('kind')->all());
        $this->assertSame($afiliado->id, $intentos[0]->actor_user_id);
        $this->assertSame($admin->id, $intentos[1]->actor_user_id);
        $this->assertSame('camara-categorias@ejemplo.test', $intentos[1]->recipient_email);
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SENT, $intentos[1]->result);
    }

    #[Test]
    public function a_second_retry_never_sends_a_second_notice(): void
    {
        [$solicitud] = $this->solicitudFallida();
        config(['mail.default' => 'smtp']);
        $this->destinatario('camara-categorias@ejemplo.test');
        $admin = $this->superAdmin();
        $service = app(TaxonomyCategoryRequestService::class);

        $service->retryNotice($solicitud, $admin);

        try {
            $service->retryNotice($solicitud, $admin);
            $this->fail('Un aviso ya aceptado no se reintenta.');
        } catch (CategoryRequestNoticeException $e) {
            $this->assertSame(CategoryRequestNoticeException::NOT_RETRYABLE, $e->reason);
        }

        Mail::assertSentCount(1);
        $this->assertSame(2, $solicitud->deliveryAttempts()->count());
    }

    #[Test]
    public function a_concurrent_retry_is_rejected_while_another_attempt_holds_the_claim_and_a_stale_claim_expires(): void
    {
        [$solicitud] = $this->solicitudFallida();
        config(['mail.default' => 'smtp']);
        $this->destinatario('camara-categorias@ejemplo.test');
        $admin = $this->superAdmin();
        $service = app(TaxonomyCategoryRequestService::class);

        // Otro proceso tiene la reserva vigente (lo que deja el UPDATE condicional de deliverNotice).
        TaxonomyCategoryRequest::query()->whereKey($solicitud->id)->update([
            'delivery_claim_token' => 'otro-proceso',
            'delivery_claimed_at' => now(),
        ]);

        try {
            $service->retryNotice($solicitud, $admin);
            $this->fail('Con una reserva vigente el segundo intento debe rechazarse.');
        } catch (CategoryRequestNoticeException $e) {
            $this->assertSame(CategoryRequestNoticeException::IN_PROGRESS, $e->reason);
        }
        Mail::assertNothingSent();
        $this->assertSame(1, $solicitud->deliveryAttempts()->count());

        // El proceso que la tenía murió: pasado el plazo, la solicitud vuelve a poder reintentarse.
        TaxonomyCategoryRequest::query()->whereKey($solicitud->id)->update([
            'delivery_claimed_at' => now()->subMinutes(TaxonomyCategoryRequestService::CLAIM_TTL_MINUTES + 1),
        ]);

        $intento = $service->retryNotice($solicitud, $admin);
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SENT, $intento->result);
        Mail::assertSentCount(1);
        $this->assertNull($solicitud->fresh()->delivery_claim_token, 'La reserva se libera al cerrar el intento.');
    }

    #[Test]
    public function only_super_admin_can_retry_even_calling_the_service_directly(): void
    {
        [$solicitud, , $afiliado] = $this->solicitudFallida();
        $this->destinatario('camara-categorias@ejemplo.test');

        $this->expectException(AuthorizationException::class);
        try {
            app(TaxonomyCategoryRequestService::class)->retryNotice($solicitud, $afiliado);
        } finally {
            Mail::assertNothingSent();
            $this->assertSame(1, $solicitud->deliveryAttempts()->count());
        }
    }

    #[Test]
    public function a_failed_retry_is_recorded_and_stays_retryable(): void
    {
        [$solicitud] = $this->solicitudFallida();
        config(['mail.default' => 'smtp']);
        $this->destinatario('camara-categorias@ejemplo.test');
        $admin = $this->superAdmin();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP 451 temporal'));

        $intento = app(TaxonomyCategoryRequestService::class)->retryNotice($solicitud, $admin);

        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_FAILED, $intento->result);
        $this->assertSame(TaxonomyCategoryRequest::ERROR_ENVIO, $intento->error_code);
        $fresca = $solicitud->fresh();
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_FAILED, $fresca->delivery_status);
        $this->assertTrue($fresca->isRetryable());
        $this->assertNull($fresca->delivery_claim_token);
        $this->assertNull($fresca->mail_sent_at);
    }

    // ---------------------------------------------------------------------------------------
    // Transporte honesto
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function log_and_array_transports_are_recorded_as_simulated_never_as_sent(): void
    {
        foreach (['log', 'array'] as $mailer) {
            [$solicitud] = $this->solicitudFallida();
            config(['mail.default' => $mailer]);
            $this->destinatario('camara-categorias@ejemplo.test');
            $admin = $this->superAdmin();
            $this->actingAs($admin);

            Livewire::actingAs($admin)->test(ListTaxonomyCategoryRequests::class)
                ->callTableAction('reintentarAviso', $solicitud)
                ->assertNotified('Aviso simulado: no se envió ningún correo');

            $fresca = $solicitud->fresh();
            $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SIMULATED, $fresca->delivery_status, "Transporte {$mailer}");
            $this->assertSame($mailer, $fresca->delivery_transport);
            $this->assertNull($fresca->mail_sent_at, 'Una simulación no tiene fecha de envío.');
            $this->assertFalse($fresca->isRetryable());
            $this->assertStringNotContainsString('Aceptado', $fresca->deliveryLabel());
        }

        $this->assertStringContainsString('Simulado', TaxonomyCategoryRequestService::transportDescription());
        config(['mail.default' => 'smtp']);
        $this->assertStringContainsString('No confirma que haya llegado al buzón', TaxonomyCategoryRequestService::transportDescription());
    }

    #[Test]
    public function under_a_simulated_transport_the_affiliate_is_told_the_notice_is_pending_not_sent(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        $this->destinatario('camara-categorias@ejemplo.test');
        [$empresa, $afiliado] = $this->empresaConAfiliado();

        Livewire::actingAs($afiliado)->test(TaxonomyCategoriesRelationManager::class, ['ownerRecord' => $empresa, 'pageClass' => EditEmpresa::class])
            ->callTableAction('solicitarRevisionCategoria', data: self::DATOS)
            ->assertHasNoTableActionErrors()
            ->assertNotified(TaxonomyCategoriesRelationManager::SOLICITUD_WARNING_TITLE);

        $solicitud = TaxonomyCategoryRequest::query()->where('empresa_id', $empresa->id)->sole();
        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_SIMULATED, $solicitud->delivery_status);

        $aviso = json_encode(TaxonomyCategoriesRelationManager::notificacionSolicitud($solicitud)->toArray());
        $this->assertStringContainsString("#{$solicitud->id}", $aviso);
        // El afiliado no ve el modo del servidor ni la palabra "simulado": solo que está registrada.
        $this->assertStringNotContainsString('servidor', $aviso);
        $this->assertStringNotContainsString('simulad', $aviso);
        $this->assertStringContainsString('quedó registrada', $aviso);
    }

    // ---------------------------------------------------------------------------------------
    // Configuración, taxonomía y migración
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function the_settings_page_shows_the_transport_mode_without_secrets_and_saving_does_not_resend(): void
    {
        [$solicitud] = $this->solicitudFallida();
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'smtp.interno-no-mostrar.test']);
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::actingAs($admin)->test(CategoryRequestSettingsPage::class)
            ->assertSee('Modo de envío del servidor')
            ->assertSee('Simulado')
            ->assertDontSee('smtp.interno-no-mostrar.test')
            ->fillForm(['category_request_recipient_email' => 'responsable@ejemplo.test'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Configuración guardada');

        $this->assertSame(TaxonomyCategoryRequest::DELIVERY_FAILED, $solicitud->fresh()->delivery_status, 'Configurar no reenvía.');
        $this->assertSame(1, $solicitud->deliveryAttempts()->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_admin_help_explains_navigation_and_states_and_the_affiliate_manual_stays_free_of_internals(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::actingAs($admin)->test(CategoryRequestHelpPage::class)
            ->assertSee('Taxonomía CPV → Solicitudes de categoría')
            ->assertSee('Taxonomía CPV → Configuración de solicitudes')
            ->assertSee(TaxonomyCategoryRequestResource::RETRY_LABEL)
            ->assertSee(TaxonomyCategoryRequest::DELIVERY_LABELS[TaxonomyCategoryRequest::DELIVERY_SIMULATED])
            ->assertSee('No confirma');

        $ayuda = (string) file_get_contents(CategoryRequestHelpPage::sourcePath());
        $this->assertDoesNotMatchRegularExpression('/\b(app|docs|resources|vendor|storage|tests|config)\/[\w.\/-]*/i', $ayuda, 'Sin rutas internas.');
        $this->assertDoesNotMatchRegularExpression('/\b(MAIL_|password|token|secret|credential|host)\b/i', $ayuda, 'Sin configuración ni secretos del servidor.');

        $manual = (string) file_get_contents(resource_path('manual/manual_usuario.md'));
        $this->assertStringContainsString('número de solicitud', $manual);
        $this->assertStringContainsString('Solicitud registrada, aviso por correo pendiente', $manual);
        $this->assertDoesNotMatchRegularExpression('/simulad|modo de envío|Reintentar aviso|servidor de correo/iu', $manual, 'El afiliado no ve detalles del transporte ni operaciones de administración.');
    }

    #[Test]
    public function viewing_and_retrying_never_write_taxonomy_tables(): void
    {
        [$solicitud] = $this->solicitudFallida();
        config(['mail.default' => 'smtp']);
        $this->destinatario('camara-categorias@ejemplo.test');
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $escrituras = [];
        DB::connection('pgsql')->listen(function ($query) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete)\s+(into\s+|from\s+)?"?(\w+)"?/i', $query->sql, $m)) {
                $escrituras[] = strtolower($m[3]);
            }
        });

        Livewire::actingAs($admin)->test(ListTaxonomyCategoryRequests::class);
        Livewire::actingAs($admin)->test(ViewTaxonomyCategoryRequest::class, ['record' => $solicitud->getKey()]);
        app(TaxonomyCategoryRequestService::class)->retryNotice($solicitud, $admin);

        $this->assertNotEmpty($escrituras);
        $this->assertSame([], array_values(array_diff(array_unique($escrituras), [
            'taxonomy_category_requests',
            'taxonomy_category_request_delivery_attempts',
        ])), 'Solo se escriben la solicitud y su historial de avisos.');
    }

    #[Test]
    public function the_migration_preserves_existing_requests_and_settings_and_backfills_history_only(): void
    {
        // Una solicitud como la #1 de staging: registrada ANTES de esta versión, sin historial.
        [$empresa, $afiliado] = $this->empresaConAfiliado();
        $id = DB::connection('pgsql')->table('taxonomy_category_requests')->insertGetId([
            'empresa_id' => $empresa->id,
            'user_id' => $afiliado->id,
            'necesidad' => 'Necesidad previa',
            'justificacion' => 'OTRO',
            'detalle' => str_repeat('Detalle previo a la migración. ', 2),
            'context_snapshot' => json_encode(['empresa' => ['name' => $empresa->name]]),
            'request_status' => 'pending_review',
            'delivery_status' => 'failed',
            'delivery_error' => 'recipient_not_configured',
            'submitted_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        TaxonomySelectionSettings::current()->update(['max_categorias_principales' => 3, 'category_request_recipient_email' => null]);

        $filaAntes = (array) DB::connection('pgsql')->table('taxonomy_category_requests')->find($id);
        $settingsAntes = (array) DB::connection('pgsql')->table('taxonomy_selection_settings')->first();

        // up() es idempotente: re-aplicarla es exactamente lo que haría sobre staging.
        (require database_path('migrations/'.self::MIGRATION_C))->up();
        (require database_path('migrations/'.self::MIGRATION_C))->up();

        $this->assertEquals($filaAntes, (array) DB::connection('pgsql')->table('taxonomy_category_requests')->find($id), 'La solicitud existente no se reescribe.');
        $this->assertEquals($settingsAntes, (array) DB::connection('pgsql')->table('taxonomy_selection_settings')->first(), 'La configuración no cambia.');

        $historial = DB::connection('pgsql')->table('taxonomy_category_request_delivery_attempts')->where('request_id', $id)->get();
        $this->assertCount(1, $historial, 'Una sola fila histórica, aunque la migración corra dos veces.');
        $this->assertSame('historical', $historial[0]->kind);
        $this->assertSame('failed', $historial[0]->result);
        $this->assertSame('recipient_not_configured', $historial[0]->error_code);
        $this->assertNull($historial[0]->transport);

        // El nuevo estado es aceptado por la restricción, y uno inventado sigue rechazado.
        DB::connection('pgsql')->table('taxonomy_category_requests')->where('id', $id)->update(['delivery_status' => 'simulated']);
        $this->assertSame('simulated', DB::connection('pgsql')->table('taxonomy_category_requests')->where('id', $id)->value('delivery_status'));
        DB::connection('pgsql')->statement('SAVEPOINT estado_invalido');
        try {
            DB::connection('pgsql')->table('taxonomy_category_requests')->where('id', $id)->update(['delivery_status' => 'delivered']);
            $this->fail('Un estado fuera de la lista debe rechazarse.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('taxonomy_category_requests_delivery_status_check', $e->getMessage());
        } finally {
            DB::connection('pgsql')->statement('ROLLBACK TO SAVEPOINT estado_invalido');
        }
    }
}

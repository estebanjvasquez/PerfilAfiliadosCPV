<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\PresenceRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\SustainabilitiesRelationManager;
use App\Models\EmpresaModuleStatus;
use App\Models\Management;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Filament\Concerns\ProfileModuleFixtures;
use Tests\TestCase;

/**
 * CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (Issue #2 `6212967641`): matriz de aceptación del PRIMER guardado
 * y de las actualizaciones de los cinco módulos del perfil, sobre PostgreSQL, con los eventos reales.
 *
 * Cada fila de módulo se crea por la acción real de su pestaña (relation manager de Filament): nunca
 * con inserts, withoutEvents ni createQuietly. Cardinalidad real, inventariada en el código:
 *   - Sistemas de gestión, Recursos y Experiencia: UNA fila por empresa (first() o create()). Las
 *     experiencias son entradas dentro de `exp_year`.
 *   - Presencia internacional: UNA fila (el botón «Crear» se oculta cuando ya existe).
 *   - Sostenibilidad: VARIAS filas por empresa («Crear» siempre visible).
 *
 * La atomicidad ante una falla, que exige no envolver el test en una transacción, está en
 * FirstSaveAtomicityTest.
 */
class FirstSaveModulesTest extends TestCase
{
    use DatabaseTransactions;
    use ProfileModuleFixtures;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        // Un error SQL dentro de una llamada de Livewire tiene que verse tal cual, no como un 500.
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seedCatalogs();
    }

    // ---------------------------------------------------------------- Sistemas de gestión (6 secciones)

    #[Test]
    public function management_first_save_then_each_section_updates_the_same_row_and_keeps_siblings(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $this->assertCount(0, $this->rows('management', $empresa), 'Precondición: la empresa no tiene fila.');

        $rm = $this->manager(ManagementRelationManager::class, $empresa, $user);
        $rm->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'iso17025' => false, 'quality_otros' => true, 'quality_data' => ['a' => ['quality_otros_name' => 'ZZZ CALIDAD']]])
            ->assertHasNoTableActionErrors();

        $rows = $this->rows('management', $empresa);
        $this->assertCount(1, $rows, 'El primer guardado crea UNA fila.');
        $this->assertSame($user->id, (int) $rows[0]->user_id, 'El actor es el usuario que guardó.');

        foreach ([
            'ambiente' => ['iso14001' => true, 'iso50001' => false, 'environment_otros' => false],
            'credibilidad' => ['dun' => true, 'iso37001' => false, 'credibility_otros' => false],
            'seguridad' => ['iso45001' => false, 'ovid' => true, 'security_otros' => true, 'security_data' => ['b' => ['security_otros_name' => 'ZZZ SEGURIDAD']]],
            'proyectos' => ['pmi' => true, 'pmi_otros' => false],
            'seguridad_info' => ['iso27001' => true, 'info_otros' => false],
        ] as $section => $data) {
            $rm->actWith($section, data: ['no_aplica' => false] + $data)->assertHasNoTableActionErrors();
        }

        $rows = $this->rows('management', $empresa);
        $this->assertCount(1, $rows, 'Las seis secciones actualizan la misma fila: sin duplicados.');
        $m = Management::findOrFail($rows[0]->id);
        $this->assertTrue($m->iso9001);
        $this->assertFalse($m->iso17025);
        $this->assertTrue($m->quality_otros);
        $this->assertSame(['ZZZ CALIDAD'], collect($m->quality_data)->pluck('quality_otros_name')->values()->all());
        $this->assertTrue($m->iso14001);
        $this->assertTrue($m->dun);
        $this->assertTrue($m->ovid);
        $this->assertSame(['ZZZ SEGURIDAD'], collect($m->security_data)->pluck('security_otros_name')->values()->all());
        $this->assertTrue($m->pmi);
        $this->assertTrue($m->iso27001);
        $this->assertSame($user->id, (int) $m->user_id, 'Actualizar no cambia el actor de la creación.');
    }

    #[Test]
    public function management_unchecked_values_and_no_aplica_round_trip_keep_other_sections(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(ManagementRelationManager::class, $empresa, $user);
        $rm->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'iso17025' => true, 'quality_otros' => false])->assertHasNoTableActionErrors();
        $rm->actWith('seguridad', data: ['no_aplica' => false, 'iso45001' => true, 'ovid' => false, 'security_otros' => false])->assertHasNoTableActionErrors();

        // «No Aplica» en Calidad: marca la sección, y no toca los datos ya guardados.
        $rm->actWith('calidad', data: ['no_aplica' => true])->assertHasNoTableActionErrors();
        $this->assertTrue(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_GESTION, 'calidad'));
        $this->assertTrue(Management::where('empresa_id', $empresa->id)->value('iso9001'));

        // Revertir «No Aplica» y destildar: se quita la marca y el valor queda en falso.
        $rm->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => false, 'iso17025' => true, 'quality_otros' => false])->assertHasNoTableActionErrors();
        $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_GESTION, 'calidad'));
        $m = Management::where('empresa_id', $empresa->id)->firstOrFail();
        $this->assertFalse($m->iso9001);
        $this->assertTrue($m->iso17025);
        $this->assertTrue($m->iso45001, 'La sección hermana Seguridad no se pierde.');
        $this->assertCount(1, $this->rows('management', $empresa));
    }

    #[Test]
    public function management_module_no_aplica_is_cleared_by_the_first_real_save_as_before(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(ManagementRelationManager::class, $empresa, $user);
        $rm->actWith('no_aplica', data: ['no_aplica' => true]);
        $this->assertTrue(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_GESTION));

        $rm->actWith('proyectos', data: ['no_aplica' => false, 'pmi' => true, 'pmi_otros' => false])->assertHasNoTableActionErrors();
        $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_GESTION), 'Management::created() quita el «No aplica» del módulo, igual que antes.');
    }

    #[Test]
    public function management_validation_rejection_persists_nothing(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $this->manager(ManagementRelationManager::class, $empresa, $user)
            ->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => true, 'quality_data' => ['a' => ['quality_otros_name' => '']]])
            ->assertHasTableActionErrors();

        $this->assertCount(0, $this->rows('management', $empresa), 'Un dato inválido no crea la fila.');
    }

    #[Test]
    public function management_repeated_click_does_not_duplicate(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(ManagementRelationManager::class, $empresa, $user);
        $rm->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => false]);
        $rm->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => false]);

        $this->assertCount(1, $this->rows('management', $empresa));
    }

    // ---------------------------------------------------------------- Recursos en Venezuela (4 tipos)

    #[Test]
    public function assets_first_save_then_the_four_types_update_one_row(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(AssetsRelationManager::class, $empresa, $user);

        $rm->actWith('recursos_humanos', data: ['no_aplica' => false, 'employee' => ['a' => ['employee_type' => '2', 'junior_q' => 1, 'medium_q' => 2, 'senior_q' => 3]]])
            ->assertHasNoTableActionErrors();
        $rows = $this->rows('assets', $empresa);
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]->user_id);

        $rm->actWith('maquinaria', data: ['no_aplica' => false, 'machinery' => ['a' => ['machinery_name' => '1', 'machinery_qid' => '2', 'machinery_est' => '3']]])->assertHasNoTableActionErrors();
        $rm->actWith('instalaciones', data: ['no_aplica' => false, 'facility' => ['a' => ['facility_type' => '0', 'facility_q' => 2, 'facility_surf' => 150, 'facility_own' => '3']]])->assertHasNoTableActionErrors();
        $rm->actWith('inventario', data: ['no_aplica' => false, 'inventory' => ['a' => ['inventory_name' => '0', 'inventory_q' => '1', 'inventory_unit' => 'KG', 'inventory_est' => '1']]])->assertHasNoTableActionErrors();

        $rows = $this->rows('assets', $empresa);
        $this->assertCount(1, $rows, 'Los cuatro tipos usan la misma fila.');
        $asset = $empresa->assets()->firstOrFail();
        $this->assertSame('2', (string) collect($asset->employee)->first()['employee_type']);
        $this->assertSame(3, (int) collect($asset->employee)->first()['senior_q']);
        $this->assertSame('1', (string) collect($asset->machinery)->first()['machinery_name']);
        $this->assertSame('3', (string) collect($asset->facility)->first()['facility_own']);
        $this->assertSame('KG', collect($asset->inventory)->first()['inventory_unit']);
        $this->assertSame($user->id, (int) $asset->user_id);
    }

    #[Test]
    public function assets_no_aplica_keeps_saved_data_and_validation_rejects_without_writing(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(AssetsRelationManager::class, $empresa, $user);

        $rm->actWith('recursos_humanos', data: ['no_aplica' => false, 'employee' => ['a' => ['employee_type' => '', 'junior_q' => 1, 'medium_q' => 0, 'senior_q' => 0]]])
            ->assertHasTableActionErrors();
        $this->assertCount(0, $this->rows('assets', $empresa), 'Sin tipo de recurso no se crea nada.');

        // El modal con errores queda montado; se sigue como lo haría el usuario, en una pestaña recargada.
        $rm = $this->manager(AssetsRelationManager::class, $empresa, $user);
        $rm->actWith('maquinaria', data: ['no_aplica' => false, 'machinery' => ['a' => ['machinery_name' => '0', 'machinery_qid' => '1', 'machinery_est' => '1']]])->assertHasNoTableActionErrors();
        $rm->actWith('maquinaria', data: ['no_aplica' => true])->assertHasNoTableActionErrors();
        $this->assertTrue(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_RECURSOS, 'machinery'));
        $this->assertNotEmpty($empresa->assets()->firstOrFail()->machinery, '«No Aplica» no borra lo guardado.');
        $rm->actWith('maquinaria', data: ['no_aplica' => false, 'machinery' => ['a' => ['machinery_name' => '0', 'machinery_qid' => '4', 'machinery_est' => '4']]])->assertHasNoTableActionErrors();
        $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_RECURSOS, 'machinery'));
        $this->assertSame('4', (string) collect($empresa->assets()->firstOrFail()->machinery)->first()['machinery_qid']);
        $this->assertCount(1, $this->rows('assets', $empresa));
    }

    // ---------------------------------------------------------------- Experiencia relevante (entradas)

    #[Test]
    public function experiences_first_add_creates_the_row_and_further_adds_edits_and_deletes_are_entries(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(ExperiencesRelationManager::class, $empresa, $user);

        $rm->actWith('agregar_experiencia', data: ['exp_year' => '2020', 'infrasectors_id' => $this->catalog['infrasector'], 'magnitud' => null, 'Descripcion' => 'ZZZ proyecto uno'])
            ->assertHasNoTableActionErrors();
        $rows = $this->rows('experiences', $empresa);
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]->user_id);

        // Varias experiencias son legítimas: son entradas de la misma fila, no filas nuevas.
        $rm->actWith('agregar_experiencia', data: ['exp_year' => '2022', 'Descripcion' => 'ZZZ proyecto dos'])->assertHasNoTableActionErrors();
        $this->assertCount(1, $this->rows('experiences', $empresa));
        $entries = $empresa->experiences()->firstOrFail()->exp_year;
        $this->assertCount(2, $entries);

        $firstKey = (string) array_key_first($entries);
        $rm->actWith('editar', $firstKey, data: ['exp_year' => '2021', 'Descripcion' => 'ZZZ proyecto uno editado'])->assertHasNoTableActionErrors();
        $entries = $empresa->experiences()->firstOrFail()->exp_year;
        $this->assertSame('2021', (string) $entries[$firstKey]['data']['exp_year']);
        $this->assertSame('ZZZ proyecto uno editado', $entries[$firstKey]['data']['Descripcion']);

        $rm->actWith('eliminar', $firstKey);
        $this->assertCount(1, $empresa->experiences()->firstOrFail()->exp_year);
        $this->assertCount(1, $this->rows('experiences', $empresa));
    }

    #[Test]
    public function experiences_validation_rejection_persists_nothing(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $this->manager(ExperiencesRelationManager::class, $empresa, $user)
            ->actWith('agregar_experiencia', data: ['exp_year' => '', 'Descripcion' => 'sin año'])
            ->assertHasTableActionErrors(['exp_year']);

        $this->assertCount(0, $this->rows('experiences', $empresa));
    }

    // ---------------------------------------------------------------- Presencia internacional (oficinas + experiencia)

    #[Test]
    public function presence_first_create_with_offices_and_experience_then_edit(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $rm = $this->manager(PresenceRelationManager::class, $empresa, $user);

        $rm->actWith('create', data: [
            'has_offices' => true,
            'office_data' => ['a' => ['country_id' => $this->catalog['country'], 'offices_surf' => '120', 'employees_q' => 4, 'status' => true]],
            'has_experience' => true,
            'experience_data' => ['b' => ['expcountry_id' => $this->catalog['country'], 'projects_q' => 3, 'role' => '2', 'executed_q' => '3', 'expemployees_q' => 10, 'main_clients' => 'ZZZ CLIENTE']],
        ])->assertHasNoTableActionErrors();

        $rows = $this->rows('presences', $empresa);
        $this->assertCount(1, $rows);
        $this->assertSame($user->id, (int) $rows[0]->user_id);
        $presence = $empresa->presence()->firstOrFail();
        $this->assertTrue((bool) $presence->has_offices);
        $this->assertSame('2', (string) collect($presence->experience_data)->first()['role']);

        $rm->assertTableActionHidden('create');

        $rm->actWith('edit', $presence, data: ['has_offices' => false, 'has_experience' => true, 'experience_data' => ['b' => ['expcountry_id' => $this->catalog['country'], 'projects_q' => 5, 'role' => '3', 'executed_q' => '4']]])
            ->assertHasNoTableActionErrors();
        $presence->refresh();
        $this->assertFalse((bool) $presence->has_offices);
        $this->assertSame('3', (string) collect($presence->experience_data)->first()['role']);
        $this->assertSame($user->id, (int) $presence->user_id);
        $this->assertCount(1, $this->rows('presences', $empresa));
    }

    #[Test]
    public function presence_validation_rejection_persists_nothing(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $this->manager(PresenceRelationManager::class, $empresa, $user)
            ->actWith('create', data: ['has_offices' => false, 'has_experience' => true, 'experience_data' => ['b' => ['expcountry_id' => $this->catalog['country'], 'projects_q' => 1, 'role' => null, 'executed_q' => null]]])
            ->assertHasTableActionErrors();

        $this->assertCount(0, $this->rows('presences', $empresa));
    }

    // ---------------------------------------------------------------- Sostenibilidad (varias filas)

    #[Test]
    public function sustainability_allows_several_rows_and_keeps_each_rows_own_actor(): void
    {
        $owner = $this->affiliate();
        $empresa = $this->companyOf($owner);
        $this->manager(SustainabilitiesRelationManager::class, $empresa, $owner)
            ->actWith('create', data: ['areas_id' => $this->catalog['area1'], 'sust_status' => '2'])->assertHasNoTableActionErrors();

        // Un segundo usuario de la MISMA empresa agrega otro enfoque.
        $colleague = $this->affiliate();
        $empresa->users()->attach($colleague->id);
        $this->manager(SustainabilitiesRelationManager::class, $empresa, $colleague)
            ->actWith('create', data: ['areas_id' => $this->catalog['area2'], 'sust_status' => '1'])->assertHasNoTableActionErrors();

        $rows = $this->rows('sustainabilities', $empresa);
        $this->assertCount(2, $rows, 'Varias filas son legítimas en este módulo.');
        $this->assertSame([$owner->id, $colleague->id], $rows->pluck('user_id')->map(fn ($v) => (int) $v)->all(),
            'Cada fila conserva su propio actor: crear la segunda no reescribe el actor de la primera.');

        $this->manager(SustainabilitiesRelationManager::class, $empresa, $owner)
            ->actWith('create', data: ['areas_id' => $this->catalog['area1'], 'sust_status' => null])
            ->assertHasTableActionErrors(['sust_status']);
        $this->assertCount(2, $this->rows('sustainabilities', $empresa), 'Un dato inválido no agrega fila.');
    }

    // ---------------------------------------------------------------- Aislamiento, permisos y actor

    #[Test]
    public function two_companies_do_not_leak_and_another_company_is_not_reachable(): void
    {
        $userA = $this->affiliate();
        $empresaA = $this->companyOf($userA);
        $userB = $this->affiliate();
        $empresaB = $this->companyOf($userB);

        $this->manager(ManagementRelationManager::class, $empresaB, $userB)
            ->actWith('calidad', data: ['no_aplica' => false, 'iso17025' => true, 'quality_otros' => false]);
        $this->manager(ManagementRelationManager::class, $empresaA, $userA)
            ->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => false]);
        $this->manager(SustainabilitiesRelationManager::class, $empresaA, $userA)
            ->actWith('create', data: ['areas_id' => $this->catalog['area1'], 'sust_status' => '2']);

        $b = Management::where('empresa_id', $empresaB->id)->firstOrFail();
        $this->assertTrue($b->iso17025);
        $this->assertFalse($b->iso9001, 'El guardado de A no toca la fila de B.');
        $this->assertSame($userB->id, (int) $b->user_id);
        $this->assertCount(0, $this->rows('sustainabilities', $empresaB));

        // La empresa de B no aparece para A: la consulta del recurso solo trae empresas vinculadas.
        $this->withExceptionHandling();
        $this->actingAs($userA)->get(EmpresaResource::getUrl('edit', ['record' => $empresaB]))->assertNotFound();
        $this->actingAs($userA)->get(EmpresaResource::getUrl('edit', ['record' => $empresaA]))->assertOk();
    }

    #[Test]
    public function a_user_without_the_module_permission_cannot_open_or_invoke_it(): void
    {
        $user = $this->affiliate(withPermissions: false);
        $empresa = $this->companyOf($user);

        $this->expectException(HttpException::class);
        $this->manager(ManagementRelationManager::class, $empresa, $user)
            ->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => false]);
    }

    #[Test]
    public function an_admin_first_save_is_attributed_to_the_admin(): void
    {
        $admin = $this->admin();
        $empresa = $this->companyOf($admin);
        $this->manager(PresenceRelationManager::class, $empresa, $admin)
            ->actWith('create', data: ['has_offices' => false, 'has_experience' => false])->assertHasNoTableActionErrors();

        $rows = $this->rows('presences', $empresa);
        $this->assertCount(1, $rows);
        $this->assertSame($admin->id, (int) $rows[0]->user_id);
    }

    #[Test]
    public function without_a_session_the_model_records_no_actor_instead_of_inventing_one(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        Auth::logout();

        // Contrato de la capa de modelo: el actor es opcional (columna nullable). La interfaz siempre
        // tiene sesión; fuera de ella se deja NULL y no se fabrica un usuario.
        $management = $empresa->management()->create(['iso9001' => true]);

        $this->assertNull($management->fresh()->user_id);
        $this->assertCount(1, $this->rows('management', $empresa));
    }
}

<?php

namespace Tests\Feature\Taxonomy;

use App\Filament\Resources\TaxonomyCategoryResource;
use App\Filament\Resources\TaxonomyCategoryResource\Pages\EditTaxonomyCategory;
use App\Filament\Resources\TaxonomyFamilyResource\Pages\EditTaxonomyFamily;
use App\Filament\Resources\TaxonomyGroupResource\Pages\EditTaxonomyGroup;
use App\Models\TaxonomyAuditLog;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyTermCpvRelation;
use App\Models\User;
use App\Services\Taxonomy\CategoryCodeChanger;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Taxonomy\Concerns\TaxonomySearchFixtures;
use Tests\TestCase;

/**
 * TASK-0015 (pedido del propietario, 2026-11-09): al editar una categoría se ve a qué Grupo y Familia
 * pertenece, y el código, la familia y el tipo solo cambian con la acción «Cambiar código o familia»,
 * que valida la nomenclatura y deja el motivo en la auditoría.
 *
 * El árbol es el de `TaxonomySearchFixtures`: Familia F con las hijas `.01G` (Balancín), `.02S`, `.03G`
 * y `.04G` (Cabezal, con una relación término→CPV).
 */
class CategoryCodeChangeTest extends TestCase
{
    use DatabaseTransactions;
    use TaxonomySearchFixtures;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->buildBalancinTree();
    }

    private function editor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), [
            'view_any_taxonomy::category', 'view_taxonomy::category', 'update_taxonomy::category',
        ]));

        return $user->fresh();
    }

    private function changer(): CategoryCodeChanger
    {
        return app(CategoryCodeChanger::class);
    }

    private function family(): TaxonomyCategory
    {
        return $this->nodes['familia'];
    }

    /** @return array<string, list<string>> */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Se esperaba un error de validación.');
    }

    #[Test]
    public function the_edit_form_shows_group_and_family_and_locks_code_family_and_type(): void
    {
        $user = $this->editor();
        $category = $this->nodes['balancin'];

        Livewire::actingAs($user)
            ->test(EditTaxonomyCategory::class, ['record' => $category->getKey()])
            ->assertSee(TaxonomyCategoryResource::nodeLabel($this->nodes['grupo']->fresh('translations')))
            ->assertSee(TaxonomyCategoryResource::nodeLabel($this->family()->fresh('translations')))
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldIsDisabled('tipo_oferta')
            ->assertFormFieldIsHidden('parent_id')
            ->assertSee(TaxonomyCategoryResource::CODE_EDIT_HELP)
            ->assertActionVisible('cambiarCodigo');
    }

    #[Test]
    public function a_normal_save_never_changes_code_family_or_type_even_if_sent(): void
    {
        $user = $this->editor();
        $category = $this->nodes['balancin'];

        Livewire::actingAs($user)
            ->test(EditTaxonomyCategory::class, ['record' => $category->getKey()])
            ->fillForm(['code' => 'CPV-99.99.99G', 'tipo_oferta' => TaxonomyCategory::TIPO_SERVICIO, 'parent_id' => $this->nodes['grupo']->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $category->fresh();
        $this->assertSame($category->code, $fresh->code);
        $this->assertSame($category->parent_id, $fresh->parent_id);
        $this->assertSame($category->tipo_oferta, $fresh->tipo_oferta);
    }

    #[Test]
    public function the_nomenclature_is_validated(): void
    {
        $user = $this->editor();
        $category = $this->nodes['cabezal'];
        $f = $this->family()->code;
        $bien = TaxonomyCategory::TIPO_BIEN;
        $reason = 'Corrección pedida por la Cámara';

        $cases = [
            'otra familia' => [$this->family()->id, $bien, 'CPV-000.00.05G', 'code'],
            'sin prefijo' => [$this->family()->id, $bien, ltrim(substr($f, 4)).'.05G', 'code'],
            'sin letra' => [$this->family()->id, $bien, "{$f}.05", 'code'],
            'letra que no es del tipo' => [$this->family()->id, $bien, "{$f}.05S", 'code'],
            'correlativo de 1 dígito' => [$this->family()->id, $bien, "{$f}.5G", 'code'],
            'repetido' => [$this->family()->id, $bien, $this->nodes['balancin']->code, 'code'],
            'repetido en minúsculas' => [$this->family()->id, $bien, strtolower($this->nodes['inactiva']->code), 'code'],
            'padre que es un Grupo' => [$this->nodes['grupo']->id, $bien, "{$this->nodes['grupo']->code}.05G", 'parent_id'],
        ];

        foreach ($cases as $why => [$parentId, $tipo, $code, $field]) {
            $errors = $this->errorsOf(fn () => $this->changer()->change($user, $category, $parentId, $tipo, $code, $reason));
            $this->assertArrayHasKey($field, $errors, "Caso «{$why}» ({$code}) debía rechazarse en {$field}.");
        }

        $this->assertArrayHasKey('reason', $this->errorsOf(fn () => $this->changer()->change($user, $category, $this->family()->id, $bien, "{$f}.05G", 'corto')));
        $this->assertArrayHasKey('code', $this->errorsOf(fn () => $this->changer()->change($user, $category, $this->family()->id, $bien, $category->code, $reason)), 'Sin cambios no se registra nada.');
        $this->assertSame($category->code, $category->fresh()->code, 'Ningún intento inválido cambió la categoría.');
    }

    #[Test]
    public function a_valid_change_updates_references_and_is_audited_with_the_reason(): void
    {
        $user = $this->editor();
        $this->actingAs($user);
        $category = $this->nodes['cabezal'];
        $oldCode = $category->code;
        $newCode = $this->family()->code.'.07G';

        $result = $this->changer()->change($user, $category, $this->family()->id, TaxonomyCategory::TIPO_BIEN, strtolower($newCode), 'Corrección del correlativo según el Excel de Lorenzo');

        $fresh = $category->fresh();
        $this->assertSame($newCode, $fresh->code, 'Se guarda en mayúsculas.');
        $this->assertSame($this->family()->path.'/'.$newCode, $fresh->path);
        $this->assertSame(1, $result['term_relations']);
        $this->assertSame($newCode, TaxonomyTermCpvRelation::query()->where('category_id', $category->id)->value('cpv_code'),
            'La relación del diccionario sigue a la categoría con el código nuevo.');

        $logs = TaxonomyAuditLog::query()->where('entity_type', TaxonomyCategory::class)->where('entity_id', $category->id)->get();
        $codeLog = $logs->firstWhere('field', 'code');
        $this->assertSame($oldCode, $codeLog->old_value);
        $this->assertSame($newCode, $codeLog->new_value);
        $this->assertSame('Corrección del correlativo según el Excel de Lorenzo', $codeLog->reason);
        $this->assertSame($user->id, (int) $codeLog->user_id);
        $this->assertNotNull($logs->firstWhere('field', 'code_references'));
        $this->assertNull($logs->firstWhere('field', 'parent_id'), 'Solo se registran los campos que cambiaron.');
    }

    #[Test]
    public function the_action_moves_a_category_to_another_family_with_the_next_correlative(): void
    {
        $user = $this->editor();
        $otherFamily = $this->node($this->nodes['grupo']->code.'.99', $this->nodes['grupo'], ['es' => "Otra familia {$this->tok}"]);
        $this->node($otherFamily->code.'.01G', $otherFamily, ['es' => "Hermana {$this->tok}"]);
        $category = $this->nodes['bajo'];
        $expected = TaxonomyCategoryResource::buildNextCode($otherFamily->id, TaxonomyCategory::TIPO_SERVICIO);

        $this->assertSame($otherFamily->code.'.02S', $expected);

        Livewire::actingAs($user)
            ->test(EditTaxonomyCategory::class, ['record' => $category->getKey()])
            ->mountAction('cambiarCodigo')
            ->setActionData(['group_id' => $this->nodes['grupo']->id])
            ->setActionData(['parent_id' => $otherFamily->id])
            ->setActionData(['tipo_oferta' => TaxonomyCategory::TIPO_SERVICIO])
            ->setActionData(['modo' => EditTaxonomyCategory::MODE_AUTO])
            ->assertActionDataSet(['code' => $expected])
            ->setActionData(['reason' => 'La categoría estaba en la familia equivocada'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $fresh = $category->fresh();
        $this->assertSame($expected, $fresh->code);
        $this->assertSame($otherFamily->id, $fresh->parent_id);
        $this->assertSame(TaxonomyCategory::LEVEL_CATEGORY, $fresh->level);
        $this->assertSame('La categoría estaba en la familia equivocada', TaxonomyAuditLog::query()
            ->where('entity_type', TaxonomyCategory::class)->where('entity_id', $category->id)->where('field', 'parent_id')->value('reason'));
    }

    #[Test]
    public function an_invalid_code_from_the_action_keeps_the_category_unchanged(): void
    {
        $category = $this->nodes['bajo'];

        Livewire::actingAs($this->editor())
            ->test(EditTaxonomyCategory::class, ['record' => $category->getKey()])
            ->callAction('cambiarCodigo', data: [
                'group_id' => $this->nodes['grupo']->id,
                'parent_id' => $this->family()->id,
                'tipo_oferta' => TaxonomyCategory::TIPO_SERVICIO,
                'modo' => EditTaxonomyCategory::MODE_MANUAL,
                'code' => $this->family()->code.'.08G',
                'reason' => 'Prueba de una letra que no corresponde',
            ])
            ->assertNotified('No se cambió el código');

        $this->assertSame($category->code, $category->fresh()->code);
    }

    #[Test]
    public function changing_requires_the_update_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['view_any_taxonomy::category', 'view_taxonomy::category']));

        $this->expectException(AuthorizationException::class);
        $this->changer()->change($viewer->fresh(), $this->nodes['cabezal'], $this->family()->id, TaxonomyCategory::TIPO_BIEN, $this->family()->code.'.07G', 'Sin permiso no se puede');
    }

    #[Test]
    public function changing_a_family_code_renumbers_its_categories_references_and_audit(): void
    {
        $user = $this->editor();
        $this->actingAs($user);
        $family = $this->family();
        $group = $this->nodes['grupo'];
        $newCode = $group->code.'.99';
        $oldChildCodes = collect(['balancin', 'bajo', 'inactiva', 'cabezal'])->mapWithKeys(fn ($k) => [$k => $this->nodes[$k]->code]);

        $result = $this->changer()->change($user, $family, $group->id, null, $newCode, 'La familia se renumera según la tabla de Lorenzo');

        $this->assertSame(4, $result['descendants']);
        $this->assertSame($newCode, $family->fresh()->code);
        foreach ($oldChildCodes as $key => $old) {
            $child = $this->nodes[$key]->fresh();
            $expected = $newCode.substr($old, strlen($this->family()->code));
            $this->assertSame($expected, $child->code, "{$key} conserva su correlativo y su letra.");
            $this->assertSame($group->code.'/'.$newCode.'/'.$expected, $child->path);
            $this->assertSame(TaxonomyCategory::LEVEL_CATEGORY, $child->level);
        }

        $this->assertSame($this->nodes['cabezal']->fresh()->code,
            TaxonomyTermCpvRelation::query()->where('category_id', $this->nodes['cabezal']->id)->value('cpv_code'));
        $this->assertSame(2, $result['term_relations'], 'Las dos relaciones del árbol (balancín y cabezal) pasan al código nuevo.');

        $childLog = TaxonomyAuditLog::query()->where('entity_type', TaxonomyCategory::class)
            ->where('entity_id', $this->nodes['balancin']->id)->where('field', 'code')->sole();
        $this->assertSame($oldChildCodes['balancin'], $childLog->old_value);
        $this->assertStringContainsString('La familia se renumera según la tabla de Lorenzo', $childLog->reason);
        $this->assertStringContainsString('Renumerada por el cambio de', $childLog->reason);
    }

    #[Test]
    public function changing_a_group_code_renumbers_families_and_categories(): void
    {
        $user = $this->editor();
        $this->actingAs($user);
        $free = collect(range(50, 99))->map(fn ($n) => "CPV-{$n}")
            ->diff(TaxonomyCategory::query()->where('level', TaxonomyCategory::LEVEL_GROUP)->pluck('code'))
            ->values();
        $this->assertGreaterThanOrEqual(2, $free->count());

        $group = $this->node($free[0], null, ['es' => "Grupo de dos dígitos {$this->tok}"]);
        $family = $this->node($group->code.'.01', $group, ['es' => "Familia {$this->tok}"]);
        $category = $this->node($family->code.'.01S', $family, ['es' => "Categoría {$this->tok}"]);

        $this->assertArrayHasKey('code', $this->errorsOf(fn () => $this->changer()->change($user, $group, null, null, 'CPV-5', 'Prueba con un dígito')));
        $this->assertArrayHasKey('code', $this->errorsOf(fn () => $this->changer()->change($user, $group, null, null, $free[1].'.01', 'Un grupo no lleva punto')));

        $result = $this->changer()->change($user, $group, null, null, $free[1], 'Reordenamiento de grupos');

        $this->assertSame(2, $result['descendants']);
        $this->assertSame($free[1].'.01', $family->fresh()->code);
        $this->assertSame($free[1].'.01.01S', $category->fresh()->code);
        $this->assertSame($free[1].'/'.$free[1].'.01/'.$free[1].'.01.01S', $category->fresh()->path);
    }

    #[Test]
    public function families_the_system_names_as_fixed_cannot_be_renumbered(): void
    {
        $user = $this->editor();
        $protected = CategoryCodeChanger::protectedCodes()[0];
        $groupCode = explode('.', $protected)[0];
        $group = TaxonomyCategory::query()->where('code', $groupCode)->first() ?? $this->node($groupCode, null, ['es' => 'Grupo protegido']);
        $family = TaxonomyCategory::query()->where('code', $protected)->first() ?? $this->node($protected, $group, ['es' => 'Familia protegida']);

        $errors = $this->errorsOf(fn () => $this->changer()->change($user, $family, $group->id, null, $groupCode.'.98', 'Intento de renumerar una familia fija'));
        $this->assertStringContainsString($protected, implode(' ', $errors['code']));

        $free = collect(range(50, 99))->map(fn ($n) => "CPV-{$n}")
            ->diff(TaxonomyCategory::query()->where('level', TaxonomyCategory::LEVEL_GROUP)->pluck('code'))->first();
        $errors = $this->errorsOf(fn () => $this->changer()->change($user, $group, null, null, $free, 'Intento de renumerar el grupo de una familia fija'));
        $this->assertStringContainsString($protected, implode(' ', $errors['code']), 'Tampoco por su grupo.');
        $this->assertSame($protected, $family->fresh()->code);
    }

    #[Test]
    public function family_and_group_forms_lock_the_code_and_the_family_action_moves_it_to_another_group(): void
    {
        $user = $this->editor();
        $family = $this->family();
        $otherGroup = $this->node('CPV-'.random_int(900, 999).'9', null, ['es' => "Otro grupo {$this->tok}"]);
        $this->node($otherGroup->code.'.01', $otherGroup, ['es' => "Familia hermana {$this->tok}"]);

        Livewire::actingAs($user)
            ->test(EditTaxonomyFamily::class, ['record' => $family->getKey()])
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldIsHidden('parent_id')
            ->assertSee(TaxonomyCategoryResource::nodeLabel($this->nodes['grupo']->fresh('translations')))
            ->mountAction('cambiarCodigo')
            ->setActionData(['parent_id' => $otherGroup->id])
            ->setActionData(['modo' => EditTaxonomyFamily::MODE_AUTO])
            ->assertActionDataSet(['code' => $otherGroup->code.'.02'])
            ->setActionData(['reason' => 'La familia corresponde a otro grupo'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($otherGroup->code.'.02', $family->fresh()->code);
        $this->assertSame($otherGroup->id, $family->fresh()->parent_id);
        $this->assertStringStartsWith($otherGroup->code.'.02.', $this->nodes['balancin']->fresh()->code);

        Livewire::actingAs($user)
            ->test(EditTaxonomyGroup::class, ['record' => $otherGroup->getKey()])
            ->assertFormFieldIsDisabled('code')
            ->assertActionVisible('cambiarCodigo');
    }
}

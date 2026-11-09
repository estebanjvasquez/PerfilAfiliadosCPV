<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCategory;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0015 (pedido del propietario en el chat, 2026-11-09): cambiar el código, la familia o el tipo
 * de una categoría (nivel 2) ya existente, con validación y motivo.
 *
 * Antes, la ficha de edición dejaba escribir cualquier código y solo comprobaba que no se repitiera
 * (el observer además exige que cuelgue del padre). Ahora el formulario normal no los cambia, y
 * esta es la única vía.
 *
 * **Validaciones del código nuevo:**
 * - empieza con `CPV-`;
 * - es hijo directo del padre elegido (`codeBelongsToParent`): `CPV-29.04.07G` solo bajo `CPV-29.04`;
 * - el último segmento es un correlativo de 2 o 3 dígitos seguido de `G` (Bien) o `S` (Servicio), y la
 *   letra corresponde al tipo elegido;
 * - no existe en ningún nivel de la taxonomía (sin distinguir mayúsculas);
 * - el padre es una Familia, como al crear;
 * - el motivo es obligatorio.
 *
 * **Referencias que guardan el código como texto**, que se actualizan en la misma transacción para no
 * dejarlas apuntando a un código que ya no existe:
 * - `taxonomy_term_cpv_relations.cpv_code` y `legacy_service_cpv_relations.cpv_code`, de las filas
 *   con `category_id` de esta categoría. La edición de una relación vuelve a resolver la categoría
 *   por `cpv_code`, y con el código viejo la perdería;
 * - `company_term_matches.cpv_code`, que no tiene `category_id`: las filas con el código viejo.
 *
 * Lo que apunta por `category_id` (empresas vinculadas, embeddings) no cambia. `path` y `level` los
 * recalcula `TaxonomyCategoryObserver`.
 *
 * **Auditoría** (`taxonomy_audit_logs`), con el motivo: una fila por cada campo que cambió (`code`,
 * `parent_id`, `tipo_oferta`) y una con los conteos de referencias actualizadas.
 */
class CategoryCodeChanger
{
    public const LAST_SEGMENT = '/^\d{2,3}[GS]$/';

    /**
     * @return array{old_code: string, new_code: string, term_relations: int, service_relations: int, crawl_matches: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function change(User $user, TaxonomyCategory $category, int $parentId, string $tipoOferta, string $newCode, string $reason): array
    {
        if (! Gate::forUser($user)->allows('update', $category)) {
            throw new AuthorizationException('No tiene permiso para editar esta categoría.');
        }

        $newCode = strtoupper(trim($newCode));
        $reason = trim($reason);

        $this->validate($category, $parentId, $tipoOferta, $newCode, $reason);

        return DB::connection('pgsql')->transaction(function () use ($category, $parentId, $tipoOferta, $newCode, $reason) {
            $category = TaxonomyCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $before = ['code' => $category->code, 'parent_id' => $category->parent_id, 'tipo_oferta' => $category->tipo_oferta];

            if (TaxonomyCategory::query()->whereRaw('upper(code) = ?', [$newCode])->whereKeyNot($category->getKey())->exists()) {
                throw ValidationException::withMessages(['code' => "El código {$newCode} ya existe."]);
            }

            $category->forceFill(['code' => $newCode, 'parent_id' => $parentId, 'tipo_oferta' => $tipoOferta])->save();

            $counts = ['term_relations' => 0, 'service_relations' => 0, 'crawl_matches' => 0];

            if ($before['code'] !== $newCode) {
                $db = DB::connection('pgsql');
                $counts['term_relations'] = $db->table('taxonomy_term_cpv_relations')
                    ->where('category_id', $category->id)->update(['cpv_code' => $newCode, 'updated_at' => now()]);
                $counts['service_relations'] = $db->table('legacy_service_cpv_relations')
                    ->where('category_id', $category->id)->update(['cpv_code' => $newCode, 'updated_at' => now()]);
                $counts['crawl_matches'] = $db->table('company_term_matches')
                    ->where('cpv_code', $before['code'])->update(['cpv_code' => $newCode]);
            }

            foreach (['code' => $newCode, 'parent_id' => $parentId, 'tipo_oferta' => $tipoOferta] as $field => $value) {
                TaxonomyAuditLogger::record(
                    entityType: TaxonomyCategory::class,
                    entityId: $category->id,
                    field: $field,
                    oldValue: $before[$field],
                    newValue: $value,
                    reason: $reason,
                );
            }

            if (array_sum($counts) > 0) {
                TaxonomyAuditLogger::recordEvent(
                    entityType: TaxonomyCategory::class,
                    entityId: $category->id,
                    field: 'code_references',
                    oldValue: $before['code'],
                    newValue: json_encode($counts + ['new_code' => $newCode]),
                    reason: $reason,
                );
            }

            return ['old_code' => $before['code'], 'new_code' => $newCode] + $counts;
        });
    }

    /**
     * Cuántas referencias de texto se actualizarían si cambia el código (para mostrarlo antes de
     * confirmar).
     *
     * @return array{empresas: int, term_relations: int, service_relations: int, crawl_matches: int}
     */
    public function impact(TaxonomyCategory $category): array
    {
        $db = DB::connection('pgsql');

        return [
            'empresas' => $category->empresaLinks()->count(),
            'term_relations' => $db->table('taxonomy_term_cpv_relations')->where('category_id', $category->id)->count(),
            'service_relations' => $db->table('legacy_service_cpv_relations')->where('category_id', $category->id)->count(),
            'crawl_matches' => $db->table('company_term_matches')->where('cpv_code', $category->code)->count(),
        ];
    }

    /** @throws ValidationException */
    private function validate(TaxonomyCategory $category, int $parentId, string $tipoOferta, string $newCode, string $reason): void
    {
        $errors = [];

        if ((int) $category->level !== TaxonomyCategory::LEVEL_CATEGORY) {
            $errors['code'][] = 'Esta acción es solo para categorías (nivel 2).';
        }

        $parent = TaxonomyCategory::query()->find($parentId);
        // Medido el 2026-11-09 en staging: las 3.116 categorías cuelgan de una Familia; ninguna de un
        // Grupo. Igual que al crear, el padre es siempre una Familia.
        if (! $parent || (int) $parent->level !== TaxonomyCategory::LEVEL_FAMILY) {
            $errors['parent_id'][] = 'Elija una Familia existente como padre.';
        }

        if (! in_array($tipoOferta, [TaxonomyCategory::TIPO_BIEN, TaxonomyCategory::TIPO_SERVICIO], true)) {
            $errors['tipo_oferta'][] = 'Elija el tipo de oferta: Bien o Servicio.';
        }

        if (! str_starts_with($newCode, TaxonomyCategory::CODE_PREFIX)) {
            $errors['code'][] = 'El código debe empezar con «'.TaxonomyCategory::CODE_PREFIX.'».';
        } elseif ($parent && ! TaxonomyCategory::codeBelongsToParent($newCode, $parent->code)) {
            $errors['code'][] = "El código debe ser {$parent->code}.<correlativo><G|S>: un segmento más que el padre elegido.";
        } else {
            $segments = explode('.', TaxonomyCategory::stripCodePrefix($newCode));
            $last = end($segments);

            if (! preg_match(self::LAST_SEGMENT, (string) $last)) {
                $errors['code'][] = 'El último segmento debe ser un correlativo de 2 o 3 dígitos seguido de G (Bien) o S (Servicio), por ejemplo «07G».';
            } elseif (substr((string) $last, -1) !== ($tipoOferta === TaxonomyCategory::TIPO_SERVICIO ? 'S' : 'G')) {
                $errors['code'][] = 'La letra final no corresponde al tipo: G es Bien y S es Servicio.';
            }
        }

        if (TaxonomyCategory::query()->whereRaw('upper(code) = ?', [$newCode])->whereKeyNot($category->getKey())->exists()) {
            $errors['code'][] = "El código {$newCode} ya existe.";
        }

        if ($newCode === $category->code && $parentId === (int) $category->parent_id && $tipoOferta === $category->tipo_oferta) {
            $errors['code'][] = 'No hay cambios: el código, la familia y el tipo son los actuales.';
        }

        if (mb_strlen($reason) < 10) {
            $errors['reason'][] = 'Explique el motivo del cambio (al menos 10 caracteres). Queda en la auditoría.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

<?php

namespace App\Services\Taxonomy;

use App\Console\Commands\BuildEmpresaSearchDocuments;
use App\Console\Commands\HomologateServicesTaxonomy;
use App\Models\TaxonomyCategory;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0015 (pedidos del propietario en el chat, 2026-11-09): cambiar el código, el padre o el tipo
 * de un nodo de la taxonomía (Grupo, Familia o Categoría) con validación y motivo. Es la única vía:
 * los formularios de edición ya no cambian esos campos.
 *
 * **Nomenclatura**, medida el 2026-11-09 en staging: 48 grupos, 333 familias y 3.116 categorías la
 * cumplen todas.
 *
 * | Nivel | Código | Padre |
 * |---|---|---|
 * | Grupo (0) | `CPV-NN` | ninguno |
 * | Familia (1) | `CPV-<grupo>.NN` | un Grupo |
 * | Categoría (2) | `CPV-<familia>.<NN o NNN><G o S>`; G si es Bien, S si es Servicio | una Familia |
 *
 * El código nuevo además no puede existir ya (sin distinguir mayúsculas), y el motivo es obligatorio.
 *
 * **Renumeración de descendientes.** El código de cada hijo empieza con el de su padre. Si cambia el
 * código de una Familia o un Grupo, todos sus descendientes cambian ese prefijo y conservan su propio
 * correlativo y su letra: `CPV-29.04` → `CPV-29.07` lleva `CPV-29.04.02G` a `CPV-29.07.02G`. Antes de
 * aplicar se comprueba que ningún código renumerado exista ya fuera del subárbol.
 *
 * **Códigos protegidos.** Las familias que el código fuente nombra fijas
 * (`BuildEmpresaSearchDocuments::GENERIC_ATTRACTOR_FAMILY_CODES`, y también el Worker externo
 * `empresa-tools.ts`) no se renumeran, ni directamente ni por su grupo. Su exclusión del índice de
 * búsqueda dejaría de funcionar sin aviso; cambiarlas requiere primero un cambio de código.
 *
 * **Referencias** que guardan el código como texto y se actualizan en la misma transacción:
 * - `taxonomy_term_cpv_relations.cpv_code` y `legacy_service_cpv_relations.cpv_code`, por `category_id`
 *   (la edición de una relación vuelve a resolver la categoría por `cpv_code`);
 * - `company_term_matches.cpv_code`, por el código viejo (no tiene `category_id`).
 *
 * Lo que apunta por id (empresas vinculadas, embeddings) no cambia. `path` y `level` los recalcula
 * `TaxonomyCategoryObserver` para el nodo y todos sus descendientes.
 *
 * **Auditoría** (`taxonomy_audit_logs`), todo con el motivo:
 * - una fila por campo cambiado del nodo;
 * - una fila `code` por cada descendiente renumerado;
 * - una fila `code_references` con los conteos.
 */
class CategoryCodeChanger
{
    public const CATEGORY_LAST_SEGMENT = '/^\d{2,3}[GS]$/';

    public const PARENT_LAST_SEGMENT = '/^\d{2}$/';

    /** @return list<string> */
    public static function protectedCodes(): array
    {
        return array_values(array_unique(array_merge(
            BuildEmpresaSearchDocuments::GENERIC_ATTRACTOR_FAMILY_CODES,
            HomologateServicesTaxonomy::CODIGOS_FAMILIA_EXCLUIDOS,
        )));
    }

    /**
     * @return array{old_code: string, new_code: string, descendants: int, term_relations: int, service_relations: int, crawl_matches: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function change(User $user, TaxonomyCategory $node, ?int $parentId, ?string $tipoOferta, string $newCode, string $reason): array
    {
        if (! Gate::forUser($user)->allows('update', $node)) {
            throw new AuthorizationException('No tiene permiso para editar este nodo de la taxonomía.');
        }

        $level = (int) $node->level;
        $newCode = strtoupper(trim($newCode));
        $reason = trim($reason);
        $parentId = $level === TaxonomyCategory::LEVEL_GROUP ? null : $parentId;
        $tipoOferta = $level === TaxonomyCategory::LEVEL_CATEGORY ? $tipoOferta : $node->tipo_oferta;

        $this->validate($node, $parentId, $tipoOferta, $newCode, $reason);

        return DB::connection('pgsql')->transaction(function () use ($node, $parentId, $tipoOferta, $newCode, $reason) {
            $db = DB::connection('pgsql');
            $node = TaxonomyCategory::query()->whereKey($node->getKey())->lockForUpdate()->firstOrFail();
            $before = ['code' => $node->code, 'parent_id' => $node->parent_id, 'tipo_oferta' => $node->tipo_oferta];
            $oldCode = $node->code;

            /** @var array<int, array{old: string, new: string}> $renamed id => códigos del nodo y sus descendientes */
            $renamed = [];
            if ($oldCode !== $newCode) {
                $renamed[$node->id] = ['old' => $oldCode, 'new' => $newCode];

                foreach ($this->descendants($node) as $descendant) {
                    $renamed[$descendant->id] = ['old' => $descendant->code, 'new' => $newCode.substr($descendant->code, strlen($oldCode))];
                }

                $clash = TaxonomyCategory::query()
                    ->whereIn(DB::raw('upper(code)'), array_column($renamed, 'new'))
                    ->whereNotIn('id', array_keys($renamed))
                    ->value('code');
                if ($clash) {
                    throw ValidationException::withMessages(['code' => "Al renumerar quedaría repetido el código {$clash}, que ya existe."]);
                }

                // Primero los descendientes, sin el observer (sus padres todavía tienen el código viejo);
                // después el nodo, cuyo observer recalcula `path`/`level` de todo el subárbol.
                foreach ($renamed as $id => $codes) {
                    if ($id !== $node->id) {
                        $db->table('taxonomy_categories')->where('id', $id)->update(['code' => $codes['new'], 'updated_at' => now()]);
                    }
                }
            }

            $node->forceFill(['code' => $newCode, 'parent_id' => $parentId, 'tipo_oferta' => $tipoOferta])->save();

            $counts = ['descendants' => max(0, count($renamed) - 1), 'term_relations' => 0, 'service_relations' => 0, 'crawl_matches' => 0];

            if ($renamed !== []) {
                $ids = '{'.implode(',', array_keys($renamed)).'}';
                foreach (['taxonomy_term_cpv_relations' => 'term_relations', 'legacy_service_cpv_relations' => 'service_relations'] as $table => $key) {
                    $counts[$key] = $db->update(<<<SQL
                        update {$table} r set cpv_code = tc.code, updated_at = now()
                        from taxonomy_categories tc
                        where tc.id = r.category_id and tc.id = any(?::bigint[]) and r.cpv_code is distinct from tc.code
                    SQL, [$ids]);
                }

                $values = implode(', ', array_fill(0, count($renamed), '(?::text, ?::text)'));
                $counts['crawl_matches'] = $db->update(
                    "update company_term_matches m set cpv_code = v.new_code from (values {$values}) v(old_code, new_code) where m.cpv_code = v.old_code",
                    array_merge(...array_map(fn ($c) => [$c['old'], $c['new']], array_values($renamed))),
                );
            }

            foreach (['code' => $newCode, 'parent_id' => $parentId, 'tipo_oferta' => $tipoOferta] as $field => $value) {
                TaxonomyAuditLogger::record(
                    entityType: TaxonomyCategory::class,
                    entityId: $node->id,
                    field: $field,
                    oldValue: $before[$field],
                    newValue: $value,
                    reason: $reason,
                );
            }

            foreach ($renamed as $id => $codes) {
                if ($id === $node->id) {
                    continue;
                }

                TaxonomyAuditLogger::record(
                    entityType: TaxonomyCategory::class,
                    entityId: $id,
                    field: 'code',
                    oldValue: $codes['old'],
                    newValue: $codes['new'],
                    reason: "Renumerada por el cambio de {$oldCode} a {$newCode}: {$reason}",
                );
            }

            if (array_sum($counts) > 0) {
                TaxonomyAuditLogger::recordEvent(
                    entityType: TaxonomyCategory::class,
                    entityId: $node->id,
                    field: 'code_references',
                    oldValue: $oldCode,
                    newValue: json_encode($counts + ['new_code' => $newCode]),
                    reason: $reason,
                );
            }

            return ['old_code' => $oldCode, 'new_code' => $newCode] + $counts;
        });
    }

    /**
     * Qué se actualizaría si cambia el código (para mostrarlo antes de confirmar).
     *
     * @return array{descendants: int, empresas: int, term_relations: int, service_relations: int, crawl_matches: int}
     */
    public function impact(TaxonomyCategory $node): array
    {
        $db = DB::connection('pgsql');
        $ids = $this->descendants($node)->pluck('id')->push($node->id)->all();
        $codes = $this->descendants($node)->pluck('code')->push($node->code)->all();

        return [
            'descendants' => count($ids) - 1,
            'empresas' => $db->table('empresa_taxonomy_category')->whereIn('category_id', $ids)->distinct()->count('empresa_id'),
            'term_relations' => $db->table('taxonomy_term_cpv_relations')->whereIn('category_id', $ids)->count(),
            'service_relations' => $db->table('legacy_service_cpv_relations')->whereIn('category_id', $ids)->count(),
            'crawl_matches' => $db->table('company_term_matches')->whereIn('cpv_code', $codes)->count(),
        ];
    }

    /**
     * Código siguiente libre para un Grupo nuevo (`$parentId` null) o una Familia dentro de un Grupo.
     * Las categorías usan `TaxonomyCategoryResource::buildNextCode()`, que además lleva la letra del tipo.
     */
    public static function nextParentCode(?int $groupId): ?string
    {
        if ($groupId === null) {
            $max = TaxonomyCategory::query()->where('level', TaxonomyCategory::LEVEL_GROUP)->pluck('code')
                ->map(fn ($code) => TaxonomyCategory::stripCodePrefix($code))
                ->filter(fn ($n) => ctype_digit($n))
                ->map(fn ($n) => (int) $n)
                ->max() ?? 0;

            return TaxonomyCategory::CODE_PREFIX.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
        }

        $group = TaxonomyCategory::query()->find($groupId);

        if (! $group || (int) $group->level !== TaxonomyCategory::LEVEL_GROUP) {
            return null;
        }

        $max = TaxonomyCategory::query()->where('parent_id', $groupId)->where('level', TaxonomyCategory::LEVEL_FAMILY)->pluck('code')
            ->map(fn ($code) => last(explode('.', TaxonomyCategory::stripCodePrefix($code))))
            ->filter(fn ($n) => ctype_digit((string) $n))
            ->map(fn ($n) => (int) $n)
            ->max() ?? 0;

        return $group->code.'.'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    /** @return \Illuminate\Support\Collection<int, TaxonomyCategory> */
    private function descendants(TaxonomyCategory $node)
    {
        return TaxonomyCategory::query()
            ->where('path', 'like', addcslashes((string) $node->path, '\\%_').'/%')
            ->orderBy('level')
            ->get(['id', 'code', 'level']);
    }

    /** @throws ValidationException */
    private function validate(TaxonomyCategory $node, ?int $parentId, ?string $tipoOferta, string $newCode, string $reason): void
    {
        $errors = [];
        $level = (int) $node->level;
        $parent = $parentId ? TaxonomyCategory::query()->find($parentId) : null;

        if ($level === TaxonomyCategory::LEVEL_CATEGORY && (! $parent || (int) $parent->level !== TaxonomyCategory::LEVEL_FAMILY)) {
            $errors['parent_id'][] = 'Elija una Familia existente como padre.';
        }

        if ($level === TaxonomyCategory::LEVEL_FAMILY && (! $parent || (int) $parent->level !== TaxonomyCategory::LEVEL_GROUP)) {
            $errors['parent_id'][] = 'Elija un Grupo existente como padre.';
        }

        if ($level === TaxonomyCategory::LEVEL_CATEGORY && ! in_array($tipoOferta, [TaxonomyCategory::TIPO_BIEN, TaxonomyCategory::TIPO_SERVICIO], true)) {
            $errors['tipo_oferta'][] = 'Elija el tipo de oferta: Bien o Servicio.';
        }

        $segments = explode('.', TaxonomyCategory::stripCodePrefix($newCode));
        $last = (string) end($segments);

        if (! str_starts_with($newCode, TaxonomyCategory::CODE_PREFIX)) {
            $errors['code'][] = 'El código debe empezar con «'.TaxonomyCategory::CODE_PREFIX.'».';
        } elseif ($level === TaxonomyCategory::LEVEL_GROUP) {
            if (count($segments) !== 1 || ! preg_match(self::PARENT_LAST_SEGMENT, $last)) {
                $errors['code'][] = 'El código de un Grupo es CPV- seguido de 2 dígitos, por ejemplo «CPV-49».';
            }
        } elseif ($parent && ! TaxonomyCategory::codeBelongsToParent($newCode, $parent->code)) {
            $errors['code'][] = "El código debe empezar con {$parent->code}. y tener un segmento más que el padre elegido.";
        } elseif ($level === TaxonomyCategory::LEVEL_FAMILY && ! preg_match(self::PARENT_LAST_SEGMENT, $last)) {
            $errors['code'][] = 'El último segmento de una Familia son 2 dígitos, por ejemplo «CPV-29.07».';
        } elseif ($level === TaxonomyCategory::LEVEL_CATEGORY) {
            if (! preg_match(self::CATEGORY_LAST_SEGMENT, $last)) {
                $errors['code'][] = 'El último segmento debe ser un correlativo de 2 o 3 dígitos seguido de G (Bien) o S (Servicio), por ejemplo «07G».';
            } elseif (substr($last, -1) !== ($tipoOferta === TaxonomyCategory::TIPO_SERVICIO ? 'S' : 'G')) {
                $errors['code'][] = 'La letra final no corresponde al tipo: G es Bien y S es Servicio.';
            }
        }

        if (TaxonomyCategory::query()->whereRaw('upper(code) = ?', [$newCode])->whereKeyNot($node->getKey())->exists()) {
            $errors['code'][] = "El código {$newCode} ya existe.";
        }

        if ($newCode !== $node->code) {
            $touched = $this->descendants($node)->pluck('code')->push($node->code);
            $protected = $touched->intersect(self::protectedCodes())->values();

            if ($protected->isNotEmpty()) {
                $errors['code'][] = 'No se puede renumerar '.$protected->implode(', ').': el sistema usa ese código de familia de forma fija para excluirla del índice de búsqueda. Pídalo como cambio de sistema.';
            }
        }

        if ($newCode === $node->code && $parentId === ($node->parent_id === null ? null : (int) $node->parent_id) && $tipoOferta === $node->tipo_oferta) {
            $errors['code'][] = 'No hay cambios: el código, el padre y el tipo son los actuales.';
        }

        if (mb_strlen($reason) < 10) {
            $errors['reason'][] = 'Explique el motivo del cambio (al menos 10 caracteres). Queda en la auditoría.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

<?php

namespace App\Support\Taxonomy;

use App\Models\TaxonomyCategory;
use Illuminate\Support\Facades\DB;

/**
 * CPV-TAXONOMY-MAP: el árbol publicado de la Taxonomía CPV (Grupo > Familia > Categoría) que ve el
 * afiliado en «Mapa de la taxonomía». Solo lectura: una consulta SELECT, ninguna escritura y, en
 * esta v1, sin caché (revisión `6191485610`, punto A: se construye directo y se mide).
 *
 * REGLA DE PUBLICACIÓN. «Publicado» es `is_active = true`: es el interruptor «Activa» que administra
 * la Cámara (al importar, solo «BELONGS» entra activa; ver ImportTaxonomy) y el mismo filtro que usa
 * el alta de categorías del afiliado. Además, cada nodo necesita una cadena de ancestros visible y
 * bien formada:
 *   - Grupo: level 0, sin padre.
 *   - Familia: level 1, cuyo padre es un Grupo visible.
 *   - Categoría: level 2, cuyo padre es una Familia visible.
 * Lo que no cumple (inactivo, bajo un ancestro inactivo u oculto, nivel que no corresponde al del
 * padre, padre inexistente) queda FUERA y nunca se "promueve" a raíz. Como la consulta solo trae
 * nodos activos, el hijo de un nodo inactivo no encuentra a su padre y queda fuera por la misma
 * regla.
 *
 * QUÉ SALE. Solo nombres, el tipo de oferta y conteos. Nada de códigos, ids, `branch`/`subbranch`,
 * relevancia ni otros campos internos: el resultado no los incluye, así que la vista no puede
 * filtrarlos por error.
 *
 * ORDEN. Alfabético en español en los tres niveles, sin distinguir mayúsculas ni tildes (la «ñ» va
 * después de la «n»), y determinista ante nombres iguales: desempata el nombre exacto y luego el id
 * interno, que se usa solo para ordenar y no se devuelve. No cambia el orden ni los datos de la
 * taxonomía de origen.
 */
class TaxonomyMapBuilder
{
    public const TIPO_OTRO = 'otro';

    private const SIN_NOMBRE = '(sin nombre)';

    private ?\Collator $collator = null;

    /**
     * @return array{
     *     groups: array<int, array{name: string, families: array<int, array{name: string, categories: array<int, array{name: string, tipo: string}>, counts: array<string, int>}>, counts: array<string, int>}>,
     *     totals: array<string, int>
     * }
     */
    public function build(): array
    {
        $rows = DB::connection('pgsql')->select("
            select c.id, c.parent_id, c.level, c.tipo_oferta, es.name as name_es, en.name as name_en
            from taxonomy_categories c
            left join taxonomy_category_translations es on es.category_id = c.id and es.locale = 'es'
            left join taxonomy_category_translations en on en.category_id = c.id and en.locale = 'en'
            where c.is_active = true
        ");

        $groups = [];
        $families = [];
        $categories = [];

        foreach ($rows as $row) {
            $node = [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'name' => $this->nameOf($row),
                'tipo' => $row->tipo_oferta,
            ];

            match ((int) $row->level) {
                TaxonomyCategory::LEVEL_GROUP => $node['parent_id'] === null ? $groups[$node['id']] = $node : null,
                TaxonomyCategory::LEVEL_FAMILY => $families[$node['id']] = $node,
                TaxonomyCategory::LEVEL_CATEGORY => $categories[$node['id']] = $node,
                default => null,
            };
        }

        $categoriesByFamily = [];
        foreach ($categories as $category) {
            if (isset($families[$category['parent_id']]) && isset($groups[$families[$category['parent_id']]['parent_id']])) {
                $categoriesByFamily[$category['parent_id']][] = $category;
            }
        }

        $familiesByGroup = [];
        foreach ($families as $family) {
            if (isset($groups[$family['parent_id']])) {
                $familiesByGroup[$family['parent_id']][] = $family;
            }
        }

        $totals = ['groups' => 0, 'families' => 0, 'categories' => 0, 'empty_groups' => 0, 'empty_families' => 0] + $this->zeroTipos();
        $tree = [];

        foreach ($this->sorted($groups) as $group) {
            $groupCounts = ['families' => 0, 'categories' => 0] + $this->zeroTipos();
            $familyNodes = [];

            foreach ($this->sorted($familiesByGroup[$group['id']] ?? []) as $family) {
                $familyCounts = ['categories' => 0] + $this->zeroTipos();
                $categoryNodes = [];

                foreach ($this->sorted($categoriesByFamily[$family['id']] ?? []) as $category) {
                    $tipo = $this->tipoOf($category['tipo']);
                    $categoryNodes[] = ['name' => $category['name'], 'tipo' => $tipo];
                    $familyCounts['categories']++;
                    $familyCounts[$tipo]++;
                }

                $familyNodes[] = ['name' => $family['name'], 'categories' => $categoryNodes, 'counts' => $familyCounts];
                $groupCounts['families']++;
                foreach ($familyCounts as $key => $value) {
                    $groupCounts[$key] += $value;
                }
                $totals['empty_families'] += $familyCounts['categories'] === 0 ? 1 : 0;
            }

            $tree[] = ['name' => $group['name'], 'families' => $familyNodes, 'counts' => $groupCounts];
            $totals['groups']++;
            foreach ($groupCounts as $key => $value) {
                $totals[$key] += $value;
            }
            $totals['empty_groups'] += $groupCounts['categories'] === 0 ? 1 : 0;
        }

        return ['groups' => $tree, 'totals' => $totals];
    }

    /** Nombre en español; si falta, en inglés; nunca el código. */
    private function nameOf(object $row): string
    {
        foreach ([$row->name_es, $row->name_en] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return self::SIN_NOMBRE;
    }

    /**
     * «bien» y «servicio» se cuentan como tales. Cualquier otro valor, o ninguno, se cuenta aparte
     * como «otro» (revisión `6191485610`, punto 3): no se reparte en silencio entre bienes y
     * servicios.
     */
    private function tipoOf(?string $tipo): string
    {
        return in_array($tipo, [TaxonomyCategory::TIPO_BIEN, TaxonomyCategory::TIPO_SERVICIO], true) ? $tipo : self::TIPO_OTRO;
    }

    /** @return array<string, int> */
    private function zeroTipos(): array
    {
        return [TaxonomyCategory::TIPO_BIEN => 0, TaxonomyCategory::TIPO_SERVICIO => 0, self::TIPO_OTRO => 0];
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $nodes
     * @return array<int, array{id: int, name: string}>
     */
    private function sorted(array $nodes): array
    {
        usort($nodes, fn (array $a, array $b) => $this->compareNames($a['name'], $b['name'])
            ?: strcmp($a['name'], $b['name'])
            ?: $a['id'] <=> $b['id']);

        return $nodes;
    }

    public function compareNames(string $a, string $b): int
    {
        if ($this->collator === null && class_exists(\Collator::class)) {
            $this->collator = new \Collator('es');
            // PRIMARY: compara solo letras base, sin mayúsculas ni tildes; en español «ñ» es una
            // letra propia, así que sigue yendo después de «n».
            $this->collator->setStrength(\Collator::PRIMARY);
        }

        if ($this->collator !== null) {
            return $this->collator->compare($a, $b) <=> 0;
        }

        // Sin ext-intl (no es el caso del contenedor): aproximación sin tildes ni mayúsculas.
        return strcmp($this->fold($a), $this->fold($b));
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => "n\u{FFFF}"]);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3: siembra las 3 tablas de vocabulario gobernado del Canonical Concept Builder
 * (`taxonomy_concept_types`, `taxonomy_concept_relation_types`, `taxonomy_intent_markers`) - mismo
 * criterio que `SeedTaxonomySettings`: `insertOrIgnore` por clave natural, nunca pisa una fila que
 * un administrador ya haya editado/desactivado.
 *
 * Las 9 categorías de `concept_type` y los 9 marcadores de intención son EXACTAMENTE las listas
 * genéricas pedidas en las secciones 4 y 2 del pedido de Phase 3 - ninguna es específica de
 * gandola/cabria/artificial lift ni de ningún ejemplo concreto.
 */
class SeedTaxonomyConceptVocabulary extends Command
{
    protected $signature = 'taxonomy:seed-concept-vocabulary';

    protected $description = 'Phase 3: siembra concept types, relation types e intent markers (sin pisar filas ya editadas)';

    public function handle(): int
    {
        $now = now();

        $conceptTypes = [
            ['code' => 'EQUIPMENT', 'label_es' => 'Equipo', 'label_en' => 'Equipment', 'sort_order' => 1],
            ['code' => 'SERVICE', 'label_es' => 'Servicio', 'label_en' => 'Service', 'sort_order' => 2],
            ['code' => 'CAPABILITY', 'label_es' => 'Capacidad', 'label_en' => 'Capability', 'sort_order' => 3],
            ['code' => 'MATERIAL', 'label_es' => 'Material', 'label_en' => 'Material', 'sort_order' => 4],
            ['code' => 'PRODUCT', 'label_es' => 'Producto', 'label_en' => 'Product', 'sort_order' => 5],
            ['code' => 'PROCESS', 'label_es' => 'Proceso', 'label_en' => 'Process', 'sort_order' => 6],
            ['code' => 'TECHNOLOGY', 'label_es' => 'Tecnología', 'label_en' => 'Technology', 'sort_order' => 7],
            ['code' => 'OPERATION', 'label_es' => 'Operación', 'label_en' => 'Operation', 'sort_order' => 8],
            ['code' => 'LOCATION_TYPE', 'label_es' => 'Tipo de ubicación', 'label_en' => 'Location type', 'sort_order' => 9],
        ];
        $conceptTypesInserted = DB::connection('pgsql')->table('taxonomy_concept_types')->insertOrIgnore(
            array_map(fn ($t) => $t + ['active' => true, 'created_at' => $now, 'updated_at' => $now], $conceptTypes)
        );

        $relationTypes = [
            [
                'code' => 'RELATED_TO', 'label_es' => 'Relacionado con', 'label_en' => 'Related to',
                'directional' => false, 'inverse_relation_code' => null, 'default_weight' => 0.5,
            ],
            [
                'code' => 'PART_OF', 'label_es' => 'Parte de', 'label_en' => 'Part of',
                'directional' => true, 'inverse_relation_code' => 'HAS_PART', 'default_weight' => 0.7,
            ],
            [
                'code' => 'HAS_PART', 'label_es' => 'Tiene como parte', 'label_en' => 'Has part',
                'directional' => true, 'inverse_relation_code' => 'PART_OF', 'default_weight' => 0.7,
            ],
            [
                'code' => 'SUPERSEDES', 'label_es' => 'Reemplaza a', 'label_en' => 'Supersedes',
                'directional' => true, 'inverse_relation_code' => 'SUPERSEDED_BY', 'default_weight' => 0.6,
            ],
            [
                'code' => 'SUPERSEDED_BY', 'label_es' => 'Reemplazado por', 'label_en' => 'Superseded by',
                'directional' => true, 'inverse_relation_code' => 'SUPERSEDES', 'default_weight' => 0.6,
            ],
        ];
        $relationTypesInserted = DB::connection('pgsql')->table('taxonomy_concept_relation_types')->insertOrIgnore(
            array_map(fn ($t) => $t + [
                'source_concept_types' => null,
                'target_concept_types' => null,
                'max_depth' => null,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $relationTypes)
        );

        // Marcadores GENÉRICOS de intención de negocio (sección 2 del pedido, lista literal de
        // ejemplos que el propio usuario pidió tratar como clase general) - ninguno es específico
        // de un dominio (oilfield u otro).
        $intentMarkers = [
            ['marker_es' => 'mantenimiento', 'marker_en' => 'maintenance'],
            ['marker_es' => 'alquiler', 'marker_en' => 'rental'],
            ['marker_es' => 'inspección', 'marker_en' => 'inspection'],
            ['marker_es' => 'fabricación', 'marker_en' => 'manufacturing'],
            ['marker_es' => 'instalación', 'marker_en' => 'installation'],
            ['marker_es' => 'reparación', 'marker_en' => 'repair'],
            ['marker_es' => 'suministro', 'marker_en' => 'supply'],
            ['marker_es' => 'transporte', 'marker_en' => 'transport'],
            ['marker_es' => 'ingeniería', 'marker_en' => 'engineering'],
        ];
        $intentMarkersInserted = DB::connection('pgsql')->table('taxonomy_intent_markers')->insertOrIgnore(
            array_map(fn ($m) => $m + ['marker_type' => 'operation_intent', 'active' => true, 'created_at' => $now, 'updated_at' => $now], $intentMarkers)
        );

        $this->info("Concept types: {$conceptTypesInserted} nueva(s). Relation types: {$relationTypesInserted} nueva(s). Intent markers: {$intentMarkersInserted} nueva(s).");

        return self::SUCCESS;
    }
}

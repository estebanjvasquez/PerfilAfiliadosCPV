-- =====================================================================================
-- SNAPSHOT DE SOLO LECTURA, antes y despues del despliegue del candidato 0010.
-- Instruccion 6174797031, puntos 2, 3 y 6. Se corre IGUAL en las dos fases y se compara.
-- Todos los bloques valen en las dos fases: nada referencia un objeto que solo exista despues.
-- Solo catalogo, valores de configuracion del singleton y conteos agregados. Ninguna fila
-- de clientes. Se ejecuta con docs/deploy/run_readonly_preflight.php (READ ONLY + ROLLBACK).
-- =====================================================================================

-- @block S1 | Identidad y solo lectura
SELECT current_database() AS base,
       current_schema()   AS esquema,
       current_setting('transaction_read_only') AS transaccion_solo_lectura,
       CASE WHEN current_schema() = 'public' AND current_setting('transaction_read_only') = 'on'
            THEN 'OK' ELSE 'PARAR: identidad o modo de solo lectura inesperados' END AS veredicto;

-- @block S2 | Las cuatro FK aplazadas: oid y definicion exacta (un oid distinto = recreada)
SELECT rel.relname AS tabla,
       c.conname,
       c.oid::bigint AS oid_restriccion,
       pg_get_constraintdef(c.oid) AS definicion,
       c.convalidated AS validada,
       'INFO' AS veredicto
FROM pg_constraint c
JOIN pg_class rel       ON rel.oid = c.conrelid
JOIN pg_namespace relns ON relns.oid = rel.relnamespace AND relns.nspname = 'public'
WHERE c.contype = 'f'
  AND (rel.relname, c.conname) IN (('empresas', 'empresas_city_id_foreign'),
                                   ('sustainabilities', 'sustainabilities_areas_id_foreign'),
                                   ('infratypes', 'infratypes_infrasectors_id_foreign'),
                                   ('infrafacilities', 'infrafacilities_infraregion_infrasystem_id_foreign'))
ORDER BY rel.relname;

-- @block S3 | Singleton: todas sus columnas y valores (configuracion, no datos de clientes)
SELECT to_jsonb(s)::text AS fila, 'INFO' AS veredicto
FROM taxonomy_selection_settings s
ORDER BY s.id;

-- @block S4 | Registro de migraciones: conteo, lote maximo y contenido del lote maximo
SELECT m.migration, m.batch,
       (SELECT count(*) FROM migrations) AS total_registro,
       'INFO' AS veredicto
FROM migrations m
WHERE m.batch = (SELECT max(batch) FROM migrations)
ORDER BY m.migration;

-- @block S5 | Tabla nueva: existencia y filas (consulta dinamica solo si existe)
SELECT to_regclass('public.taxonomy_category_requests') IS NOT NULL AS existe,
       CASE WHEN to_regclass('public.taxonomy_category_requests') IS NULL THEN NULL
            ELSE (xpath('/row/c/text()',
                        query_to_xml('select count(*) as c from public.taxonomy_category_requests', false, true, '')))[1]::text::bigint
       END AS filas,
       'INFO' AS veredicto;

-- @block S6 | Conteos protegidos (comparar antes/despues)
SELECT b.tabla, b.filas, 'BASELINE' AS veredicto
FROM (
              SELECT 'empresa_taxonomy_category' AS tabla, count(*) AS filas FROM empresa_taxonomy_category
    UNION ALL SELECT 'empresas', count(*) FROM empresas
    UNION ALL SELECT 'migrations', count(*) FROM migrations
    UNION ALL SELECT 'migrations.max_batch', COALESCE(max(batch), 0) FROM migrations
    UNION ALL SELECT 'taxonomy_audit_log', count(*) FROM taxonomy_audit_log
    UNION ALL SELECT 'taxonomy_canonical_concepts', count(*) FROM taxonomy_canonical_concepts
    UNION ALL SELECT 'taxonomy_candidate_concept_links', count(*) FROM taxonomy_candidate_concept_links
    UNION ALL SELECT 'taxonomy_categories', count(*) FROM taxonomy_categories
    UNION ALL SELECT 'taxonomy_reviewed_proposals', count(*) FROM taxonomy_reviewed_proposals
    UNION ALL SELECT 'taxonomy_selection_settings', count(*) FROM taxonomy_selection_settings
    UNION ALL SELECT 'taxonomy_term_concepts', count(*) FROM taxonomy_term_concepts
    UNION ALL SELECT 'taxonomy_term_cpv_relations', count(*) FROM taxonomy_term_cpv_relations
    UNION ALL SELECT 'taxonomy_terms', count(*) FROM taxonomy_terms
    UNION ALL SELECT 'users', count(*) FROM users
) b
ORDER BY b.tabla;

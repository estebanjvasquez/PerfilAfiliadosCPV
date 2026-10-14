-- =====================================================================================
-- VERIFICACION POSTERIOR AL DESPLIEGUE, de solo lectura. Candidato 0010 en staging.
-- Instruccion 6174797031, puntos 5 y 6. Se corre DESPUES del workflow. Solo SELECT; se
-- ejecuta con docs/deploy/run_readonly_preflight.php (READ ONLY + ROLLBACK). Cualquier
-- veredicto distinto de OK / INFO es una PARADA del informe, no algo a reparar.
-- =====================================================================================

-- @block V1 | Identidad y solo lectura
SELECT current_database() AS base,
       current_schema()   AS esquema,
       current_setting('transaction_read_only') AS transaccion_solo_lectura,
       CASE WHEN current_schema() = 'public' AND current_setting('transaction_read_only') = 'on'
            THEN 'OK' ELSE 'PARAR: identidad o modo de solo lectura inesperados' END AS veredicto;

-- @block V2 | Las dos migraciones nuevas quedaron registradas, en el mismo lote, que es el maximo
SELECT n.nombre,
       m.batch,
       (SELECT max(batch) FROM migrations) AS lote_maximo,
       CASE WHEN m.migration IS NULL THEN 'PARAR: no quedo registrada'
            WHEN m.batch <> (SELECT max(batch) FROM migrations) THEN 'PARAR: registrada fuera del ultimo lote'
            ELSE 'OK' END AS veredicto
FROM (VALUES ('2022_12_02_000000_restore_deferred_foreign_keys'),
             ('2026_10_09_120000_create_taxonomy_category_requests_table')) AS n(nombre)
LEFT JOIN migrations m ON m.migration = n.nombre
ORDER BY n.nombre;

-- @block V3 | El ultimo lote contiene EXACTAMENTE esas dos (nada mas corrio)
SELECT m.migration,
       CASE WHEN m.migration IN ('2022_12_02_000000_restore_deferred_foreign_keys',
                                 '2026_10_09_120000_create_taxonomy_category_requests_table')
            THEN 'OK' ELSE 'PARAR: corrio una migracion no esperada' END AS veredicto
FROM migrations m
WHERE m.batch = (SELECT max(batch) FROM migrations)
ORDER BY m.migration;

-- @block V4 | Nada queda pendiente para migrate --force en el SHA desplegado
WITH candidato(nombre) AS (
    VALUES
      ('2014_10_12_000000_create_users_table'),
      ('2014_10_12_100000_create_password_resets_table'),
      ('2019_08_19_000000_create_failed_jobs_table'),
      ('2019_12_14_000001_create_personal_access_tokens_table'),
      ('2022_05_01_225251_create_countries_table'),
      ('2022_05_30_225501_create_customers_table'),
      ('2022_05_31_223828_create_empresas_table'),
      ('2022_06_13_231159_create_permission_tables'),
      ('2022_06_24_212129_create_contacts_table'),
      ('2022_06_25_213756_create_contact_empresa_table'),
      ('2022_06_26_150338_create_sectors_table'),
      ('2022_06_26_150408_create_services_table'),
      ('2022_06_27_215545_create_empresa_sector_service_table'),
      ('2022_06_28_144255_create_empresa_user_table'),
      ('2022_07_28_181005_create_states_table'),
      ('2022_07_28_183312_create_cities_table'),
      ('2022_08_03_154019_create_assets_table'),
      ('2022_08_12_145310_create_sustainabilities_table'),
      ('2022_08_15_005221_create_areas_table'),
      ('2022_08_16_204943_create_presences_table'),
      ('2022_08_22_140750_create_management_table'),
      ('2022_08_26_200657_create_experiences_table'),
      ('2022_09_01_154856_create_infratypes_table'),
      ('2022_09_01_182509_create_infrasystems_table'),
      ('2022_09_01_184733_create_infraregions_table'),
      ('2022_09_01_185804_create_infrafacilities_table'),
      ('2022_09_06_153218_create_infrasectors_table'),
      ('2022_10_19_213831_create_catalogo_view'),
      ('2022_11_06_185050_create_capacity_view'),
      ('2022_11_08_224546_create_management_view'),
      ('2022_11_10_200304_create_sustainability_view'),
      ('2022_11_10_211023_create_finance_view'),
      ('2022_11_13_181946_create_clients_view'),
      ('2022_11_16_204956_create_experience_view'),
      ('2022_11_17_222317_create_chambers_table'),
      ('2022_11_17_222702_create_chamber_empresa_table'),
      ('2022_12_01_222538_create_infraregion_infrasystem_table'),
      ('2022_12_02_000000_restore_deferred_foreign_keys'),
      ('2022_12_03_003734_create_experience_view'),
      ('2023_05_10_181749_create_resource_view'),
      ('2023_05_25_223154_create_machinery_view'),
      ('2023_05_26_204049_create_facility_view'),
      ('2023_05_31_233119_create_inventory_view'),
      ('2023_06_02_204233_create_management_det_view'),
      ('2023_09_15_225003_create_presence_view'),
      ('2026_06_12_000001_add_sector_principal_secundario_to_empresas_table'),
      ('2026_06_12_000002_create_empresa_module_status_table'),
      ('2026_07_24_000001_add_sub_type_to_empresa_module_status_table'),
      ('2026_08_04_000001_fix_null_bucket_defaults_in_report_views'),
      ('2026_09_01_130000_add_completion_percentage_to_empresas_table'),
      ('2026_09_04_083547_add_is_principal_to_contact_empresa_table'),
      ('2026_09_11_090000_create_taxonomy_category_embeddings_table'),
      ('2026_09_11_150000_create_service_embeddings_table'),
      ('2026_09_12_120000_create_service_taxonomy_homologacion_table'),
      ('2026_09_15_090000_create_taxonomy_selection_settings_table'),
      ('2026_09_15_090100_add_es_principal_to_empresa_taxonomy_category'),
      ('2026_09_16_100000_create_empresa_certifications_table'),
      ('2026_09_16_100100_create_sustainability_areas_table'),
      ('2026_09_16_100200_create_empresa_sustainability_areas_table'),
      ('2026_09_16_100300_create_empresa_experiencias_table'),
      ('2026_09_16_100400_create_empresa_experiencia_embeddings_table'),
      ('2026_09_16_110000_create_empresa_search_documents_table'),
      ('2026_09_18_090000_create_taxonomy_sources_table'),
      ('2026_09_18_090100_create_taxonomy_terms_table'),
      ('2026_09_18_090200_create_taxonomy_term_aliases_table'),
      ('2026_09_18_090300_create_taxonomy_term_cpv_relations_table'),
      ('2026_09_18_090400_create_legacy_services_table'),
      ('2026_09_18_090500_create_legacy_service_cpv_relations_table'),
      ('2026_09_18_090600_create_taxonomy_term_service_relations_table'),
      ('2026_09_18_090700_create_taxonomy_settings_table'),
      ('2026_09_18_090800_create_taxonomy_audit_log_table'),
      ('2026_09_18_090900_create_taxonomy_crawl_runs_table'),
      ('2026_09_18_091000_create_taxonomy_candidate_terms_table'),
      ('2026_09_18_091100_create_company_crawl_runs_table'),
      ('2026_09_18_091200_create_company_pages_table'),
      ('2026_09_18_091300_create_company_term_matches_table'),
      ('2026_09_18_091400_create_taxonomy_term_source_bindings_table'),
      ('2026_09_18_091500_add_provenance_columns_to_taxonomy_terms_table'),
      ('2026_09_18_091600_widen_taxonomy_terms_mapping_review_status'),
      ('2026_09_18_091700_clean_up_taxonomy_terms_mapping_review_status'),
      ('2026_09_18_091800_add_actor_columns_to_taxonomy_audit_log_table'),
      ('2026_09_18_091900_create_taxonomy_canonical_concepts_table'),
      ('2026_09_18_092000_create_taxonomy_term_concepts_table'),
      ('2026_09_18_092100_add_evidence_to_taxonomy_term_cpv_relations_table'),
      ('2026_09_18_092200_add_visible_text_to_company_pages_table'),
      ('2026_09_19_100000_add_concept_type_and_domain_to_taxonomy_canonical_concepts_table'),
      ('2026_09_19_100100_create_taxonomy_concept_types_table'),
      ('2026_09_19_100200_create_taxonomy_concept_relation_types_table'),
      ('2026_09_19_100300_create_taxonomy_concept_relations_table'),
      ('2026_09_19_100400_create_taxonomy_candidate_concept_links_table'),
      ('2026_09_19_100500_create_taxonomy_term_embeddings_table'),
      ('2026_09_19_100600_create_taxonomy_intent_markers_table'),
      ('2026_09_23_180000_add_taxonomy_state_fingerprint_to_candidate_concept_links_table'),
      ('2026_09_28_165653_add_unique_constraints_to_taxonomy_candidate_concept_links'),
      ('2026_09_28_211540_add_execution_context_to_taxonomy_audit_log_table'),
      ('2026_09_29_193000_create_taxonomy_reviewed_proposals_table'),
      ('2026_10_01_180000_add_human_confirmation_and_bilingual_group_to_taxonomy_reviewed_proposals'),
      ('2026_10_01_210000_add_confirmation_invalidation_to_taxonomy_reviewed_proposals'),
      ('2026_10_02_160000_add_supersession_lifecycle_to_taxonomy_reviewed_proposals'),
      ('2026_10_09_120000_create_taxonomy_category_requests_table')
)
SELECT c.nombre, 'PARAR: sigue pendiente despues del despliegue' AS veredicto
FROM candidato c
LEFT JOIN migrations m ON m.migration = c.nombre
WHERE m.migration IS NULL
ORDER BY c.nombre;

-- @block V5 | Tabla nueva: columnas contra la migracion 2026_10_09_120000
WITH esperadas(columna, tipo, nulable) AS (
    VALUES ('id', 'bigint', 'NO'),
           ('empresa_id', 'bigint', 'NO'),
           ('user_id', 'bigint', 'YES'),
           ('necesidad', 'text', 'NO'),
           ('justificacion', 'character varying(40)', 'NO'),
           ('detalle', 'text', 'NO'),
           ('terminos_probados', 'text', 'YES'),
           ('context_snapshot', 'jsonb', 'NO'),
           ('request_status', 'character varying(30)', 'NO'),
           ('delivery_status', 'character varying(20)', 'NO'),
           ('delivery_error', 'character varying(255)', 'YES'),
           ('recipient_email', 'character varying(255)', 'YES'),
           ('submitted_at', 'timestamp without time zone', 'NO'),
           ('mail_sent_at', 'timestamp without time zone', 'YES'),
           ('created_at', 'timestamp without time zone', 'YES'),
           ('updated_at', 'timestamp without time zone', 'YES')
), reales AS (
    SELECT a.attname::text AS columna,
           format_type(a.atttypid, a.atttypmod) AS tipo,
           CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END AS nulable
    FROM pg_attribute a
    WHERE a.attrelid = to_regclass('public.taxonomy_category_requests')
      AND a.attnum > 0 AND NOT a.attisdropped
)
SELECT COALESCE(e.columna, r.columna) AS columna,
       e.tipo AS tipo_esperado, r.tipo AS tipo_real,
       e.nulable AS nulable_esperado, r.nulable AS nulable_real,
       CASE WHEN r.columna IS NULL THEN 'PARAR: falta la columna'
            WHEN e.columna IS NULL THEN 'PARAR: columna no prevista'
            WHEN e.tipo <> r.tipo OR e.nulable <> r.nulable THEN 'PARAR: tipo o nulabilidad distintos'
            ELSE 'OK' END AS veredicto
FROM esperadas e
FULL OUTER JOIN reales r ON r.columna = e.columna
ORDER BY 1;

-- @block V6 | Tabla nueva: restricciones (PK, dos FK con sus acciones, dos CHECK)
WITH esperadas(definicion) AS (
    VALUES ('PRIMARY KEY (id)'),
           ('FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE'),
           ('FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL')
), reales AS (
    SELECT c.conname::text AS nombre, c.contype::text AS tipo, pg_get_constraintdef(c.oid) AS definicion
    FROM pg_constraint c
    WHERE c.conrelid = to_regclass('public.taxonomy_category_requests')
)
SELECT r.nombre, r.tipo, r.definicion,
       CASE WHEN r.tipo IN ('p', 'f') AND r.definicion NOT IN (SELECT definicion FROM esperadas)
              THEN 'PARAR: PK o FK distinta de la declarada'
            WHEN r.tipo = 'c' AND r.nombre NOT IN ('taxonomy_category_requests_justificacion_check',
                                                  'taxonomy_category_requests_delivery_status_check')
              THEN 'PARAR: CHECK no prevista'
            WHEN r.tipo NOT IN ('p', 'f', 'c') THEN 'PARAR: tipo de restriccion no previsto'
            ELSE 'OK' END AS veredicto
FROM reales r
UNION ALL
SELECT 'faltantes', '-', count(*)::text,
       CASE WHEN count(*) = 0 THEN 'OK' ELSE 'PARAR: falta alguna PK/FK declarada' END
FROM esperadas e
WHERE e.definicion NOT IN (SELECT definicion FROM reales)
UNION ALL
SELECT 'total', '-', count(*)::text,
       CASE WHEN count(*) = 5 THEN 'OK' ELSE 'PARAR: se esperaban 5 restricciones' END
FROM reales;

-- @block V7 | Tabla nueva: indices (siempre devuelve el total, aunque no haya ninguno)
SELECT i.indexname, i.indexdef,
       CASE WHEN i.indexname IN ('taxonomy_category_requests_pkey',
                                 'taxonomy_category_requests_empresa_idx',
                                 'taxonomy_category_requests_status_idx')
            THEN 'OK' ELSE 'PARAR: indice no previsto' END AS veredicto
FROM pg_indexes i
WHERE i.schemaname = 'public' AND i.tablename = 'taxonomy_category_requests'
UNION ALL
SELECT 'total', count(*)::text,
       CASE WHEN count(*) = 3 THEN 'OK' ELSE 'PARAR: se esperaban 3 indices' END
FROM pg_indexes i
WHERE i.schemaname = 'public' AND i.tablename = 'taxonomy_category_requests'
ORDER BY 1;

-- @block V8 | Columna nueva del singleton: existe, nulable, sin valor (siempre una fila)
SELECT 'category_request_recipient_email' AS columna,
       format_type(a.atttypid, a.atttypmod) AS tipo,
       NOT a.attnotnull AS nulable,
       (SELECT count(*) FROM taxonomy_selection_settings) AS filas_singleton,
       CASE WHEN a.attnum IS NULL THEN NULL
            ELSE (xpath('/row/c/text()', query_to_xml(
                     'select count(*) as c from public.taxonomy_selection_settings where category_request_recipient_email is not null',
                     false, true, '')))[1]::text::bigint
       END AS filas_con_destinatario,
       CASE WHEN a.attnum IS NULL THEN 'PARAR: la columna no existe'
            WHEN format_type(a.atttypid, a.atttypmod) <> 'character varying(255)' OR a.attnotnull
              THEN 'PARAR: la columna no tiene la forma declarada'
            WHEN (SELECT count(*) FROM taxonomy_selection_settings) <> 1
              THEN 'PARAR: el singleton no tiene una fila'
            ELSE 'OK' END AS veredicto
FROM (SELECT 1) AS uno
LEFT JOIN pg_attribute a
       ON a.attrelid = to_regclass('public.taxonomy_selection_settings')
      AND a.attname = 'category_request_recipient_email' AND NOT a.attisdropped;

-- @block V9 | Las cuatro FK aplazadas siguen satisfaciendo el objetivo (mismo bloque 5 del preflight)
WITH objetivo(tabla, columna, ref_tabla, ref_columna) AS (
    VALUES
      ('empresas',         'city_id',                    'cities',                  'id'),
      ('sustainabilities', 'areas_id',                   'areas',                   'id'),
      ('infratypes',       'infrasectors_id',            'infrasectors',            'id'),
      ('infrafacilities',  'infraregion_infrasystem_id', 'infraregion_infrasystem', 'id')
), fks AS (
    SELECT o.tabla, o.columna, c.conname,
           refns.nspname AS esquema_ref, ref.relname AS tabla_ref,
           (SELECT string_agg(fa.attname, ',' ORDER BY k.ord)
              FROM unnest(c.confkey) WITH ORDINALITY AS k(num, ord)
              JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.num) AS columnas_ref,
           c.confdeltype, c.confupdtype, c.convalidated,
           count(*) OVER (PARTITION BY o.tabla, o.columna) AS claves_en_columna
    FROM objetivo o
    JOIN pg_class rel       ON rel.relname = o.tabla
    JOIN pg_namespace relns ON relns.oid = rel.relnamespace AND relns.nspname = 'public'
    JOIN pg_attribute col   ON col.attrelid = rel.oid AND col.attname = o.columna AND NOT col.attisdropped
    JOIN pg_constraint c    ON c.contype = 'f' AND c.conrelid = rel.oid AND col.attnum = ANY (c.conkey)
    JOIN pg_class ref       ON ref.oid = c.confrelid
    JOIN pg_namespace refns ON refns.oid = ref.relnamespace
)
SELECT o.tabla, o.columna, f.conname, f.esquema_ref, f.tabla_ref, f.columnas_ref,
       f.confdeltype AS on_delete, f.confupdtype AS on_update, f.convalidated AS validada, f.claves_en_columna,
       CASE WHEN f.conname IS NULL THEN 'PARAR: la clave desaparecio'
            WHEN f.claves_en_columna > 1 THEN 'PARAR: ahora hay mas de una clave sobre la columna'
            WHEN f.esquema_ref <> 'public' OR f.tabla_ref <> o.ref_tabla OR f.columnas_ref <> o.ref_columna
              THEN 'PARAR: el destino cambio'
            WHEN f.confdeltype NOT IN ('a', 'r') OR f.confupdtype NOT IN ('a', 'r') OR NOT f.convalidated
              THEN 'PARAR: acciones o validez cambiaron'
            ELSE 'OK' END AS veredicto
FROM objetivo o
LEFT JOIN fks f ON f.tabla = o.tabla AND f.columna = o.columna
ORDER BY o.tabla;

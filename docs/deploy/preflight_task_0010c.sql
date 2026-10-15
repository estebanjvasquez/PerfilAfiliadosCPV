-- =====================================================================================
-- PREFLIGHT DE SOLO LECTURA para desplegar TASK-0010C en staging (instruccion 6188477132).
-- Candidato: a5772345ff0a52bd79a5b29ab063991960109271 (codigo = TESTED_HEAD 349ea61).
-- Solo SELECT; se ejecuta con docs/deploy/run_readonly_preflight.php (READ ONLY + ROLLBACK).
-- No lee el texto de ninguna solicitud: la #1 se compara por una HUELLA md5 de sus columnas
-- originales, que se vuelve a calcular despues del despliegue.
-- =====================================================================================

-- @block C1 | Identidad y solo lectura
SELECT current_database() AS base, current_schema() AS esquema,
       current_setting('transaction_read_only') AS transaccion_solo_lectura,
       CASE WHEN current_schema() = 'public' AND current_setting('transaction_read_only') = 'on'
                 AND to_regclass('public.migrations') IS NOT NULL
            THEN 'OK' ELSE 'PARAR: identidad o modo inesperados' END AS veredicto;

-- @block C2 | La migracion de 0010C todavia no esta registrada
SELECT '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests' AS nombre,
       EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests') AS registrada,
       CASE WHEN EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests')
            THEN 'ATENCION: ya registrada' ELSE 'OK' END AS veredicto;

-- @block C3 | Conjunto completo de pendientes para migrate --force en el candidato
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
      ('2026_10_09_120000_create_taxonomy_category_requests_table'),
      ('2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests')

)
SELECT c.nombre,
       CASE WHEN c.nombre = '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests'
            THEN 'OK: pendiente esperada (0010C)'
            ELSE 'PARAR: pendiente no esperada' END AS veredicto
FROM candidato c
LEFT JOIN migrations m ON m.migration = c.nombre
WHERE m.migration IS NULL
ORDER BY c.nombre;

-- @block C4 | Objetos que la migracion va a crear: no deben existir
SELECT o.objeto, o.existe,
       CASE WHEN o.existe THEN 'ATENCION: ya existe' ELSE 'OK' END AS veredicto
FROM (
              SELECT 'tabla taxonomy_category_request_delivery_attempts' AS objeto,
                     to_regclass('public.taxonomy_category_request_delivery_attempts') IS NOT NULL AS existe
    UNION ALL SELECT 'indice tcr_delivery_attempts_request_idx', to_regclass('public.tcr_delivery_attempts_request_idx') IS NOT NULL
    UNION ALL SELECT 'secuencia taxonomy_category_request_delivery_attempts_id_seq', to_regclass('public.taxonomy_category_request_delivery_attempts_id_seq') IS NOT NULL
    UNION ALL SELECT 'columna delivery_transport', EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'taxonomy_category_requests' AND column_name = 'delivery_transport')
    UNION ALL SELECT 'columna delivery_claim_token', EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'taxonomy_category_requests' AND column_name = 'delivery_claim_token')
    UNION ALL SELECT 'columna delivery_claimed_at', EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'taxonomy_category_requests' AND column_name = 'delivery_claimed_at')
) o
ORDER BY o.objeto;

-- @block C5 | El CHECK actual de delivery_status es el de 0010A y ninguna fila lo viola
SELECT c.conname, pg_get_constraintdef(c.oid) AS definicion,
       (SELECT count(*) FROM taxonomy_category_requests WHERE delivery_status NOT IN ('pending', 'sent', 'failed')) AS filas_fuera,
       CASE WHEN pg_get_constraintdef(c.oid) LIKE '%pending%' AND pg_get_constraintdef(c.oid) LIKE '%sent%'
                 AND pg_get_constraintdef(c.oid) LIKE '%failed%' AND pg_get_constraintdef(c.oid) NOT LIKE '%simulated%'
                 AND (SELECT count(*) FROM taxonomy_category_requests WHERE delivery_status NOT IN ('pending', 'sent', 'failed')) = 0
            THEN 'OK' ELSE 'ATENCION: restriccion o datos distintos a lo esperado' END AS veredicto
FROM pg_constraint c
WHERE c.conrelid = to_regclass('public.taxonomy_category_requests')
  AND c.conname = 'taxonomy_category_requests_delivery_status_check';

-- @block C6 | Prerrequisitos de la tabla nueva
SELECT t.nombre, to_regclass('public.' || t.nombre) IS NOT NULL AS existe,
       CASE WHEN to_regclass('public.' || t.nombre) IS NULL THEN 'PARAR: falta' ELSE 'OK' END AS veredicto
FROM (VALUES ('taxonomy_category_requests'), ('users')) AS t(nombre)
ORDER BY 1;

-- @block C7 | Solicitudes existentes: estado y HUELLA de las columnas originales (sin contenido)
SELECT r.id, r.request_status, r.delivery_status, COALESCE(r.delivery_error, '-') AS codigo,
       (r.recipient_email IS NOT NULL) AS con_destinatario,
       md5(concat_ws('|', r.id, r.empresa_id, r.user_id, r.necesidad, r.justificacion, r.detalle,
                     r.terminos_probados, r.context_snapshot::text, r.request_status, r.delivery_status,
                     r.delivery_error, r.recipient_email, r.submitted_at, r.mail_sent_at,
                     r.created_at, r.updated_at)) AS huella,
       (r.delivery_status IN ('sent', 'failed')) AS recibira_fila_historica,
       'INFO' AS veredicto
FROM taxonomy_category_requests r
ORDER BY r.id;

-- @block C8 | Configuracion: destinatario si/no y limites (no son datos personales)
SELECT count(*) AS filas,
       count(*) FILTER (WHERE category_request_recipient_email IS NOT NULL AND btrim(category_request_recipient_email) <> '') AS con_destinatario,
       max(max_categorias_principales) AS max_principales,
       max(max_categorias_secundarias) AS max_secundarias,
       'INFO' AS veredicto
FROM taxonomy_selection_settings;

-- @block C9 | Linea base de conteos protegidos
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
    UNION ALL SELECT 'taxonomy_category_requests', count(*) FROM taxonomy_category_requests
    UNION ALL SELECT 'taxonomy_reviewed_proposals', count(*) FROM taxonomy_reviewed_proposals
    UNION ALL SELECT 'taxonomy_selection_settings', count(*) FROM taxonomy_selection_settings
    UNION ALL SELECT 'taxonomy_term_concepts', count(*) FROM taxonomy_term_concepts
    UNION ALL SELECT 'taxonomy_term_cpv_relations', count(*) FROM taxonomy_term_cpv_relations
    UNION ALL SELECT 'taxonomy_terms', count(*) FROM taxonomy_terms
    UNION ALL SELECT 'users', count(*) FROM users
) b
ORDER BY b.tabla;

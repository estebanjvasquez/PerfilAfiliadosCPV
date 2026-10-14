-- =====================================================================================
-- PREFLIGHT DE SOLO LECTURA para el candidato de integracion TASK-0010A + TASK-0010B +
-- test-readiness (rama feature/integration-candidate-0010).
--
-- v1: instruccion 6173818866, punto 6 (ejecutado contra el fixture de pruebas).
-- v2: instruccion 6174401867 (ejecucion contra la base REAL de staging). Cambios:
--   - sin `\echo`: no es SQL. Cada bloque empieza con un marcador `-- @block <id> | <titulo>`
--     y contiene UNA sola sentencia SELECT/WITH. psql lo lee igual (son comentarios) y el
--     ejecutor docs/deploy/run_readonly_preflight.php lo parte por esos marcadores;
--   - bloque 5 con esquema, columna(s) local(es) y columna(s) referenciada(s) EXACTAS leidas
--     del catalogo (confkey), validez y numero de claves sobre la columna;
--   - bloque 5b: conteo AGREGADO de huerfanos (nunca filas) por cada clave aplazada;
--   - bloque 2b: el conjunto COMPLETO de lo que `migrate --force` aplicaria;
--   - bloques 4a/4b/4c: filas y forma del singleton, y destinos de las FK de la tabla nueva;
--   - cualquier veredicto distinto de OK / INFO / BASELINE es una PARADA. Una clave
--     foranea ausente tambien lo es en este paso (crearla sobre datos vivos no se asume
--     inocua).
--
-- ESTE ARCHIVO NO MUTA NADA. Solo SELECT. El ejecutor ademas abre la transaccion como
-- READ ONLY, rechaza cualquier bloque que no sea un unico SELECT/WITH y termina con ROLLBACK.
-- Ningun resultado de aqui se "arregla" desde aqui: se informa y se detiene.
-- =====================================================================================

-- @block 1 | Identidad de la base y modo de solo lectura
SELECT current_database()                                    AS base,
       current_schema()                                      AS esquema,
       current_setting('search_path')                        AS search_path,
       current_setting('server_version')                     AS version_servidor,
       current_setting('transaction_read_only')              AS transaccion_solo_lectura,
       pg_is_in_recovery()                                   AS en_recuperacion,
       to_regclass('public.migrations') IS NOT NULL          AS tiene_migrations,
       to_regclass('public.taxonomy_categories') IS NOT NULL AS tiene_taxonomy_categories,
       CASE
         WHEN current_schema() IS DISTINCT FROM 'public'
           THEN 'PARAR: el esquema actual no es public; este preflight y las migraciones asumen public'
         WHEN current_setting('transaction_read_only') <> 'on'
           THEN 'PARAR: la transaccion NO es de solo lectura'
         WHEN to_regclass('public.migrations') IS NULL
           THEN 'PARAR: no hay tabla migrations en public'
         WHEN to_regclass('public.taxonomy_categories') IS NULL
           THEN 'PARAR: no hay taxonomy_categories: no es la base de la app que se espera'
         ELSE 'OK'
       END AS veredicto;

-- -------------------------------------------------------------------------------------
-- 2. LAS NUEVE MIGRACIONES NUEVAS del candidato frente al registro.
--
--    Las siete `2026_09_01_1200*` estan en database/migrations/pgsql/. El `migrate --force`
--    del despliegue (deploy-contabo.yml) NO recorre esa carpeta: Laravel busca `*_*.php`
--    sin recursion y la app no registra esa ruta. Aun asi la instruccion pide comprobar
--    que esten registradas y que el resultado no se esconda: si no lo estan, es PARAR, y
--    el informe explica que el despliegue no las intentaria.
-- -------------------------------------------------------------------------------------
-- @block 2 | Migraciones nuevas del candidato: registradas o pendientes
WITH nuevas(nombre, carpeta, clase) AS (
    VALUES
      ('2022_12_02_000000_restore_deferred_foreign_keys',               'database/migrations',       'nueva: reparacion de integridad'),
      ('2026_10_09_120000_create_taxonomy_category_requests_table',     'database/migrations',       'nueva: TASK-0010A'),
      ('2026_09_01_120000_create_supplier_categories_table',            'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120001_create_supplier_category_translations_table', 'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120002_create_supplier_category_synonyms_table',     'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120003_create_empresa_supplier_categories_table',    'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120004_create_empresa_catalog_items_table',          'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120005_create_empresa_catalog_item_category_table',  'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120006_create_category_mapping_audit_table',         'database/migrations/pgsql', 'IMPORTADA de supplhi: debe estar REGISTRADA')
)
SELECT n.nombre,
       n.carpeta,
       n.clase,
       (m.migration IS NOT NULL) AS registrada,
       m.batch,
       CASE
         WHEN n.clase LIKE 'IMPORTADA%' AND m.migration IS NULL
           THEN 'PARAR: CREATE TABLE importada y NO registrada'
         WHEN n.clase LIKE 'nueva%' AND m.migration IS NOT NULL
           THEN 'ATENCION: nueva del candidato y ya registrada; revisar por que'
         ELSE 'OK'
       END AS veredicto
FROM nuevas n
LEFT JOIN migrations m ON m.migration = n.nombre
ORDER BY n.carpeta, n.nombre;

-- -------------------------------------------------------------------------------------
-- 2b. TODO lo que `migrate --force` aplicaria: archivos de database/migrations en el SHA
--     candidato que no estan en el registro. Lo esperado son EXACTAMENTE las dos nuevas.
--     Cualquier otra pendiente (incluidas las 15 vistas MySQL-only que el paso
--     db:skip-mysql-only-view-migrations insertaria en el registro) es una desviacion.
-- -------------------------------------------------------------------------------------
-- @block 2b | Conjunto completo de pendientes para migrate --force
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
SELECT c.nombre,
       CASE
         WHEN c.nombre IN ('2022_12_02_000000_restore_deferred_foreign_keys',
                           '2026_10_09_120000_create_taxonomy_category_requests_table')
           THEN 'OK: pendiente esperada (nueva del candidato)'
         ELSE 'PARAR: pendiente que NO es del candidato; migrate --force o el paso de vistas la tocaria'
       END AS veredicto
FROM candidato c
LEFT JOIN migrations m ON m.migration = c.nombre
WHERE m.migration IS NULL
ORDER BY c.nombre;

-- -------------------------------------------------------------------------------------
-- 2c. Registro sin archivo en el candidato (ni en database/migrations ni en .../pgsql).
--     `migrate` las ignora; se listan para que nada quede escondido. Solo nombre y lote.
-- -------------------------------------------------------------------------------------
-- @block 2c | Filas del registro sin archivo en el candidato
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
      ('2026_09_01_120000_create_supplier_categories_table'),
      ('2026_09_01_120001_create_supplier_category_translations_table'),
      ('2026_09_01_120002_create_supplier_category_synonyms_table'),
      ('2026_09_01_120003_create_empresa_supplier_categories_table'),
      ('2026_09_01_120004_create_empresa_catalog_items_table'),
      ('2026_09_01_120005_create_empresa_catalog_item_category_table'),
      ('2026_09_01_120006_create_category_mapping_audit_table'),
      ('2026_09_07_090000_rework_taxonomy_categories_structure')

)
SELECT m.migration AS nombre,
       m.batch,
       'INFO: registrada sin archivo en el candidato; migrate --force no la toca' AS veredicto
FROM migrations m
LEFT JOIN candidato c ON c.nombre = m.migration
WHERE c.nombre IS NULL
ORDER BY m.migration;

-- -------------------------------------------------------------------------------------
-- 3. LAS SEIS MIGRACIONES MODIFICADAS tienen que estar YA registradas: solo asi la edicion
--    es inerte sobre esta base.
-- -------------------------------------------------------------------------------------
-- @block 3 | Migraciones modificadas: tienen que estar ya registradas
WITH modificadas(nombre) AS (
    VALUES
      ('2022_05_31_223828_create_empresas_table'),
      ('2022_06_13_231159_create_permission_tables'),
      ('2022_08_12_145310_create_sustainabilities_table'),
      ('2022_09_01_154856_create_infratypes_table'),
      ('2022_09_01_185804_create_infrafacilities_table'),
      ('2026_07_24_000001_add_sub_type_to_empresa_module_status_table')
)
SELECT d.nombre,
       (m.migration IS NOT NULL) AS registrada,
       m.batch,
       CASE WHEN m.migration IS NULL
            THEN 'PARAR: migracion editada que NO corrio aqui; la edicion dejaria de ser inerte'
            ELSE 'OK' END AS veredicto
FROM modificadas d
LEFT JOIN migrations m ON m.migration = d.nombre
ORDER BY d.nombre;

-- -------------------------------------------------------------------------------------
-- 4. OBJETOS QUE LA MIGRACION DE TASK-0010A VA A CREAR: no deben existir, ni siquiera
--    restos sueltos (una secuencia o un indice con esos nombres haria fallar el CREATE).
-- -------------------------------------------------------------------------------------
-- @block 4 | Objetos de TASK-0010A ausentes y singleton presente
SELECT o.objeto,
       o.existe,
       CASE
         WHEN o.debe_existir AND NOT o.existe THEN 'PARAR: falta un prerrequisito'
         WHEN NOT o.debe_existir AND o.existe THEN 'ATENCION: ya existe; comparar con la migracion antes de aplicar'
         ELSE 'OK'
       END AS veredicto
FROM (
              SELECT 'tabla taxonomy_category_requests' AS objeto,
                     to_regclass('public.taxonomy_category_requests') IS NOT NULL AS existe, false AS debe_existir
    UNION ALL SELECT 'secuencia taxonomy_category_requests_id_seq',
                     to_regclass('public.taxonomy_category_requests_id_seq') IS NOT NULL, false
    UNION ALL SELECT 'indice taxonomy_category_requests_pkey',
                     to_regclass('public.taxonomy_category_requests_pkey') IS NOT NULL, false
    UNION ALL SELECT 'indice taxonomy_category_requests_empresa_idx',
                     to_regclass('public.taxonomy_category_requests_empresa_idx') IS NOT NULL, false
    UNION ALL SELECT 'indice taxonomy_category_requests_status_idx',
                     to_regclass('public.taxonomy_category_requests_status_idx') IS NOT NULL, false
    UNION ALL SELECT 'columna taxonomy_selection_settings.category_request_recipient_email',
                     EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = 'public' AND table_name = 'taxonomy_selection_settings'
                               AND column_name = 'category_request_recipient_email'), false
    UNION ALL SELECT 'tabla taxonomy_selection_settings (singleton que se extiende)',
                     to_regclass('public.taxonomy_selection_settings') IS NOT NULL, true
) o
ORDER BY o.objeto;

-- @block 4a | Filas del singleton (la app lee la primera; la migracion no lo crea)
SELECT count(*) AS filas,
       CASE WHEN count(*) = 1 THEN 'OK'
            ELSE 'ATENCION: el singleton no tiene exactamente una fila' END AS veredicto
FROM taxonomy_selection_settings;

-- -------------------------------------------------------------------------------------
-- 4b. FORMA del singleton contra la que crea 2026_09_15_090000. Columnas de mas o de
--     menos se informan, no se interpretan.
-- -------------------------------------------------------------------------------------
-- @block 4b | Forma del singleton taxonomy_selection_settings
WITH esperadas(columna, tipo, nulable) AS (
    VALUES ('id', 'bigint', 'NO'),
           ('max_categorias_principales', 'smallint', 'YES'),
           ('max_categorias_secundarias', 'smallint', 'YES'),
           ('created_at', 'timestamp without time zone', 'YES'),
           ('updated_at', 'timestamp without time zone', 'YES')
), reales AS (
    SELECT column_name::text AS columna, data_type::text AS tipo, is_nullable::text AS nulable
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'taxonomy_selection_settings'
)
SELECT COALESCE(e.columna, r.columna) AS columna,
       e.tipo AS tipo_esperado, r.tipo AS tipo_real,
       e.nulable AS nulable_esperado, r.nulable AS nulable_real,
       CASE
         WHEN r.columna IS NULL THEN 'PARAR: falta una columna del singleton'
         WHEN e.columna IS NULL THEN 'ATENCION: columna no prevista en el singleton'
         WHEN e.tipo <> r.tipo OR e.nulable <> r.nulable THEN 'ATENCION: tipo o nulabilidad distintos'
         ELSE 'OK'
       END AS veredicto
FROM esperadas e
FULL OUTER JOIN reales r ON r.columna = e.columna
ORDER BY 1;

-- -------------------------------------------------------------------------------------
-- 4c. DESTINOS de las FK de la tabla nueva: empresas(id) y users(id) tienen que existir y
--     estar cubiertos por una PK/UNIQUE de esa sola columna, si no el CREATE TABLE falla.
-- -------------------------------------------------------------------------------------
-- @block 4c | Destinos de las FK de taxonomy_category_requests
WITH destino(tabla, columna) AS (VALUES ('empresas', 'id'), ('users', 'id')),
resuelto AS (
    SELECT d.tabla, d.columna, a.attnum,
           format_type(a.atttypid, a.atttypmod) AS tipo,
           EXISTS (SELECT 1 FROM pg_constraint k
                   WHERE k.conrelid = to_regclass('public.' || d.tabla)
                     AND k.contype IN ('p', 'u')
                     AND k.conkey = ARRAY[a.attnum]::smallint[]) AS pk_o_unique
    FROM destino d
    LEFT JOIN pg_attribute a
           ON a.attrelid = to_regclass('public.' || d.tabla)
          AND a.attname = d.columna AND NOT a.attisdropped
)
SELECT r.tabla, r.columna, r.tipo, r.pk_o_unique,
       CASE
         WHEN r.attnum IS NULL THEN 'PARAR: falta la tabla o la columna destino'
         WHEN NOT r.pk_o_unique THEN 'PARAR: la columna destino no tiene PK/UNIQUE propia'
         WHEN r.tipo NOT IN ('bigint', 'integer') THEN 'ATENCION: tipo del destino distinto de bigint/integer'
         ELSE 'OK'
       END AS veredicto
FROM resuelto r
ORDER BY r.tabla;

-- -------------------------------------------------------------------------------------
-- 5. LAS CUATRO CLAVES FORANEAS APLAZADAS, leidas del catalogo con esquema y columnas
--    EXACTAS (conkey y confkey resueltos a nombres), no solo la tabla.
--
--    2022_12_02_000000 compara columna local, tabla y columna referenciadas y acciones,
--    pero NO el esquema de la tabla referenciada (Schema::getForeignKeys devuelve
--    foreign_schema y la migracion no lo mira). Este bloque es quien lo comprueba.
--
--    confdeltype/confupdtype: 'a' NO ACTION, 'r' RESTRICT, 'c' CASCADE, 'n' SET NULL,
--    'd' SET DEFAULT. Solo 'a' y 'r' cuentan como no propagar.
-- -------------------------------------------------------------------------------------
-- @block 5 | Claves foraneas aplazadas: esquema, columnas exactas, acciones y validez
WITH objetivo(tabla, columna, ref_tabla, ref_columna, nombre_laravel) AS (
    VALUES
      ('empresas',         'city_id',                    'cities',                  'id', 'empresas_city_id_foreign'),
      ('sustainabilities', 'areas_id',                   'areas',                   'id', 'sustainabilities_areas_id_foreign'),
      ('infratypes',       'infrasectors_id',            'infrasectors',            'id', 'infratypes_infrasectors_id_foreign'),
      ('infrafacilities',  'infraregion_infrasystem_id', 'infraregion_infrasystem', 'id', 'infrafacilities_infraregion_infrasystem_id_foreign')
), fks AS (
    SELECT o.tabla, o.columna,
           c.conname,
           relns.nspname AS esquema_local,
           (SELECT string_agg(la.attname, ',' ORDER BY k.ord)
              FROM unnest(c.conkey) WITH ORDINALITY AS k(num, ord)
              JOIN pg_attribute la ON la.attrelid = c.conrelid AND la.attnum = k.num) AS columnas_locales,
           refns.nspname AS esquema_ref,
           ref.relname   AS tabla_ref,
           (SELECT string_agg(fa.attname, ',' ORDER BY k.ord)
              FROM unnest(c.confkey) WITH ORDINALITY AS k(num, ord)
              JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.num) AS columnas_ref,
           c.confdeltype, c.confupdtype, c.convalidated, c.condeferrable,
           count(*) OVER (PARTITION BY o.tabla, o.columna) AS claves_en_columna
    FROM objetivo o
    JOIN pg_class rel       ON rel.relname = o.tabla
    JOIN pg_namespace relns ON relns.oid = rel.relnamespace AND relns.nspname = 'public'
    JOIN pg_attribute col   ON col.attrelid = rel.oid AND col.attname = o.columna AND NOT col.attisdropped
    JOIN pg_constraint c    ON c.contype = 'f' AND c.conrelid = rel.oid AND col.attnum = ANY (c.conkey)
    JOIN pg_class ref       ON ref.oid = c.confrelid
    JOIN pg_namespace refns ON refns.oid = ref.relnamespace
)
SELECT o.tabla, o.columna,
       f.conname AS nombre_actual,
       (f.conname = o.nombre_laravel) AS nombre_es_el_de_laravel,
       f.esquema_local, f.columnas_locales,
       f.esquema_ref, f.tabla_ref, f.columnas_ref,
       o.ref_tabla || '(' || o.ref_columna || ')' AS destino_de_la_migracion,
       f.confdeltype AS on_delete, f.confupdtype AS on_update,
       f.convalidated AS validada, f.condeferrable AS diferible,
       f.claves_en_columna,
       CASE
         WHEN f.conname IS NULL
           THEN 'PARAR (este paso): la clave NO existe y la migracion la crearia sobre datos vivos; ver 5b y el impacto DDL antes de seguir'
         WHEN f.claves_en_columna > 1
           THEN 'PARAR: hay mas de una clave foranea sobre esta columna'
         WHEN f.columnas_locales <> o.columna
           THEN 'PARAR: la clave es compuesta; la migracion espera una de una sola columna'
         WHEN f.esquema_ref <> 'public'
           THEN 'PARAR: la tabla referenciada esta en otro esquema (la migracion no lo detectaria)'
         WHEN f.tabla_ref <> o.ref_tabla
           THEN 'PARAR: apunta a otra tabla'
         WHEN f.columnas_ref <> o.ref_columna
           THEN 'PARAR: apunta a otra columna de la tabla correcta'
         WHEN f.confdeltype NOT IN ('a', 'r') OR f.confupdtype NOT IN ('a', 'r')
           THEN 'PARAR: la clave existente PROPAGA y el objetivo no; la migracion levantaria excepcion'
         WHEN NOT f.convalidated
           THEN 'ATENCION: la clave existe pero es NOT VALID'
         ELSE 'OK: la migracion la omitira'
       END AS veredicto
FROM objetivo o
LEFT JOIN fks f ON f.tabla = o.tabla AND f.columna = o.columna
ORDER BY o.tabla, f.conname;

-- -------------------------------------------------------------------------------------
-- 5b. HUERFANOS por clave aplazada: SOLO conteos agregados. Con la clave existente y
--     validada tienen que ser 0. Es tambien la comprobacion que haria falta ANTES de
--     proponer crear una clave que falte.
-- -------------------------------------------------------------------------------------
-- @block 5b | Huerfanos por clave aplazada (conteo agregado)
SELECT h.relacion, h.no_nulos, h.huerfanos,
       CASE WHEN h.huerfanos = 0 THEN 'OK'
            ELSE 'PARAR: hay valores sin fila referenciada; crear o validar la clave fallaria' END AS veredicto
FROM (
              SELECT 'empresas.city_id -> cities.id' AS relacion,
                     count(e.city_id) AS no_nulos,
                     count(*) FILTER (WHERE e.city_id IS NOT NULL
                                        AND NOT EXISTS (SELECT 1 FROM cities r WHERE r.id = e.city_id)) AS huerfanos
              FROM empresas e
    UNION ALL SELECT 'sustainabilities.areas_id -> areas.id',
                     count(s.areas_id),
                     count(*) FILTER (WHERE s.areas_id IS NOT NULL
                                        AND NOT EXISTS (SELECT 1 FROM areas r WHERE r.id = s.areas_id))
              FROM sustainabilities s
    UNION ALL SELECT 'infratypes.infrasectors_id -> infrasectors.id',
                     count(t.infrasectors_id),
                     count(*) FILTER (WHERE t.infrasectors_id IS NOT NULL
                                        AND NOT EXISTS (SELECT 1 FROM infrasectors r WHERE r.id = t.infrasectors_id))
              FROM infratypes t
    UNION ALL SELECT 'infrafacilities.infraregion_infrasystem_id -> infraregion_infrasystem.id',
                     count(f.infraregion_infrasystem_id),
                     count(*) FILTER (WHERE f.infraregion_infrasystem_id IS NOT NULL
                                        AND NOT EXISTS (SELECT 1 FROM infraregion_infrasystem r WHERE r.id = f.infraregion_infrasystem_id))
              FROM infrafacilities f
) h
ORDER BY h.relacion;

-- -------------------------------------------------------------------------------------
-- 6. PRERREQUISITOS: las tablas que las dos migraciones nuevas necesitan. Una ausente hace
--    que la reparacion de integridad LEVANTE excepcion (a proposito) o que el CREATE falle.
-- -------------------------------------------------------------------------------------
-- @block 6 | Tablas prerrequisito de las migraciones nuevas
SELECT t.nombre,
       to_regclass('public.' || t.nombre) IS NOT NULL AS existe,
       CASE WHEN to_regclass('public.' || t.nombre) IS NULL
            THEN 'PARAR: tabla prerrequisito ausente'
            ELSE 'OK' END AS veredicto
FROM (VALUES ('empresas'), ('cities'), ('sustainabilities'), ('areas'),
             ('infratypes'), ('infrasectors'), ('infrafacilities'), ('infraregion_infrasystem'),
             ('users'), ('taxonomy_selection_settings')) AS t(nombre)
ORDER BY t.nombre;

-- -------------------------------------------------------------------------------------
-- 7. LINEA BASE de conteos de las tablas protegidas, para comparar DESPUES del despliegue.
--    Ninguna migracion de este candidato toca datos: estos numeros tienen que repetirse
--    (salvo migrations, que sube exactamente en 2, y lo que la operacion normal agregue).
-- -------------------------------------------------------------------------------------
-- @block 7 | Linea base de conteos protegidos (debe repetirse despues)
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

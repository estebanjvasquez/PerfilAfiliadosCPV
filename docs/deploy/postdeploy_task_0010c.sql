-- =====================================================================================
-- VERIFICACION POSTERIOR AL DESPLIEGUE de TASK-0010C, de solo lectura (instruccion 6188477132).
-- Solo SELECT; se ejecuta con docs/deploy/run_readonly_preflight.php (READ ONLY + ROLLBACK).
-- La solicitud #1 se compara por la MISMA huella md5 de sus columnas originales que calculo el
-- preflight (2e64f23555b01ad30f845bdd53cedc15), sin leer su contenido.
-- =====================================================================================

-- @block D1 | Identidad y solo lectura
SELECT current_database() AS base, current_schema() AS esquema,
       current_setting('transaction_read_only') AS transaccion_solo_lectura,
       CASE WHEN current_schema() = 'public' AND current_setting('transaction_read_only') = 'on'
            THEN 'OK' ELSE 'PARAR: identidad o modo inesperados' END AS veredicto;

-- @block D2 | El ultimo lote contiene EXACTAMENTE la migracion de 0010C
SELECT m.migration, m.batch, (SELECT count(*) FROM migrations) AS total_registro,
       CASE WHEN m.migration = '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests'
            THEN 'OK' ELSE 'PARAR: corrio otra migracion' END AS veredicto
FROM migrations m
WHERE m.batch = (SELECT max(batch) FROM migrations)
ORDER BY m.migration;

-- @block D3 | La tabla de historial y sus restricciones existen
SELECT to_regclass('public.taxonomy_category_request_delivery_attempts') IS NOT NULL AS tabla,
       (SELECT count(*) FROM pg_constraint WHERE conrelid = to_regclass('public.taxonomy_category_request_delivery_attempts')) AS restricciones,
       (SELECT count(*) FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'taxonomy_category_request_delivery_attempts') AS indices,
       CASE WHEN to_regclass('public.taxonomy_category_request_delivery_attempts') IS NOT NULL
            THEN 'OK' ELSE 'PARAR: falta la tabla' END AS veredicto;

-- @block D4 | Historial: exactamente una fila historica por solicitud ya evaluada, y ninguna otra
SELECT a.request_id, a.attempt_number, a.kind, a.result, COALESCE(a.error_code, '-') AS codigo,
       COALESCE(a.transport, '(no registrado)') AS transporte, (a.recipient_email IS NOT NULL) AS con_destinatario,
       CASE WHEN a.kind = 'historical' AND a.attempt_number = 1 AND a.transport IS NULL
                 AND a.result = r.delivery_status AND COALESCE(a.error_code, '') = COALESCE(r.delivery_error, '')
            THEN 'OK' ELSE 'PARAR: fila de historial no esperada' END AS veredicto
FROM taxonomy_category_request_delivery_attempts a
JOIN taxonomy_category_requests r ON r.id = a.request_id
ORDER BY a.request_id, a.attempt_number;

-- @block D5 | Solicitudes: misma huella de columnas originales y columnas nuevas vacias
SELECT r.id, r.request_status, r.delivery_status, COALESCE(r.delivery_error, '-') AS codigo,
       md5(concat_ws('|', r.id, r.empresa_id, r.user_id, r.necesidad, r.justificacion, r.detalle,
                     r.terminos_probados, r.context_snapshot::text, r.request_status, r.delivery_status,
                     r.delivery_error, r.recipient_email, r.submitted_at, r.mail_sent_at,
                     r.created_at, r.updated_at)) AS huella,
       r.delivery_transport, r.delivery_claim_token, r.delivery_claimed_at,
       CASE WHEN r.id = 1 AND md5(concat_ws('|', r.id, r.empresa_id, r.user_id, r.necesidad, r.justificacion, r.detalle,
                     r.terminos_probados, r.context_snapshot::text, r.request_status, r.delivery_status,
                     r.delivery_error, r.recipient_email, r.submitted_at, r.mail_sent_at,
                     r.created_at, r.updated_at)) <> '2e64f23555b01ad30f845bdd53cedc15'
              THEN 'PARAR: la solicitud #1 cambio'
            WHEN r.delivery_transport IS NOT NULL OR r.delivery_claim_token IS NOT NULL OR r.delivery_claimed_at IS NOT NULL
              THEN 'PARAR: columnas nuevas con valor'
            ELSE 'OK' END AS veredicto
FROM taxonomy_category_requests r
ORDER BY r.id;

-- @block D6 | El CHECK admite simulated
SELECT pg_get_constraintdef(c.oid) AS definicion,
       CASE WHEN pg_get_constraintdef(c.oid) LIKE '%simulated%' THEN 'OK' ELSE 'PARAR: CHECK sin simulated' END AS veredicto
FROM pg_constraint c
WHERE c.conrelid = to_regclass('public.taxonomy_category_requests')
  AND c.conname = 'taxonomy_category_requests_delivery_status_check';

-- @block D7 | Configuracion sin cambios: sin destinatario
SELECT count(*) AS filas,
       count(*) FILTER (WHERE category_request_recipient_email IS NOT NULL AND btrim(category_request_recipient_email) <> '') AS con_destinatario,
       CASE WHEN count(*) FILTER (WHERE category_request_recipient_email IS NOT NULL AND btrim(category_request_recipient_email) <> '') = 0
            THEN 'OK' ELSE 'PARAR: aparecio un destinatario' END AS veredicto
FROM taxonomy_selection_settings;

-- @block D8 | Conteos protegidos (comparar con la linea base del preflight)
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
    UNION ALL SELECT 'taxonomy_category_request_delivery_attempts', count(*) FROM taxonomy_category_request_delivery_attempts
    UNION ALL SELECT 'taxonomy_category_requests', count(*) FROM taxonomy_category_requests
    UNION ALL SELECT 'taxonomy_reviewed_proposals', count(*) FROM taxonomy_reviewed_proposals
    UNION ALL SELECT 'taxonomy_selection_settings', count(*) FROM taxonomy_selection_settings
    UNION ALL SELECT 'taxonomy_term_concepts', count(*) FROM taxonomy_term_concepts
    UNION ALL SELECT 'taxonomy_term_cpv_relations', count(*) FROM taxonomy_term_cpv_relations
    UNION ALL SELECT 'taxonomy_terms', count(*) FROM taxonomy_terms
    UNION ALL SELECT 'users', count(*) FROM users
) b
ORDER BY b.tabla;

-- =====================================================================================
-- SEGUIMIENTO POST-DESPLIEGUE 0010, de solo lectura (instruccion 6187768951).
-- Despues del despliegue el propietario envio la solicitud #1. Este archivo confirma por
-- AGREGADOS que quedo registrada y con que estado de aviso, sin leer el texto de ninguna
-- solicitud ni datos de personas. Ejecutar con docs/deploy/run_readonly_preflight.php.
-- =====================================================================================

-- @block F1 | Identidad y solo lectura
SELECT current_database() AS base, current_schema() AS esquema,
       current_setting('transaction_read_only') AS transaccion_solo_lectura,
       CASE WHEN current_schema() = 'public' AND current_setting('transaction_read_only') = 'on'
            THEN 'OK' ELSE 'PARAR: identidad o modo inesperados' END AS veredicto;

-- @block F2 | Solicitudes por estado de revision y de aviso (solo conteos y codigos)
SELECT request_status, delivery_status, COALESCE(delivery_error, '(sin codigo)') AS codigo_error,
       count(*) AS solicitudes,
       min(id) AS id_minimo, max(id) AS id_maximo,
       count(*) FILTER (WHERE recipient_email IS NOT NULL) AS con_destinatario_guardado,
       count(*) FILTER (WHERE mail_sent_at IS NOT NULL) AS con_fecha_de_envio,
       'INFO' AS veredicto
FROM taxonomy_category_requests
GROUP BY request_status, delivery_status, delivery_error
ORDER BY 1, 2, 3;

-- @block F3 | Destinatario configurado en el singleton (si/no, nunca el valor)
SELECT count(*) AS filas_singleton,
       count(*) FILTER (WHERE category_request_recipient_email IS NOT NULL
                          AND btrim(category_request_recipient_email) <> '') AS con_destinatario,
       'INFO' AS veredicto
FROM taxonomy_selection_settings;

-- @block F4 | Conteos protegidos de nuevo (comparar con la linea base del despliegue)
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

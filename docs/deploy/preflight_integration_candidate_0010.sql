-- =====================================================================================
-- PREFLIGHT DE SOLO LECTURA para el candidato de integracion TASK-0010A + TASK-0010B +
-- test-readiness (rama feature/integration-candidate-0010).
--
-- Pedido por la instruccion 6173818866, punto 6. ESTE ARCHIVO NO MUTA NADA: son
-- exclusivamente SELECT. No hay INSERT, UPDATE, DELETE, ALTER, CREATE ni DROP, y no debe
-- agregarse ninguno. Se ejecuta contra la base objetivo ANTES de cualquier `migrate` y la
-- decision de desplegar se detiene si algo no coincide.
--
-- POR QUE EXISTE. El candidato agrega NUEVE migraciones, y siete de ellas son
-- `CREATE TABLE` importadas de feature/supplhi-postgres-buscador. Si esos nombres NO estan
-- registrados en la tabla `migrations` de la base viva, `migrate` intentara crear tablas que
-- alli ya existen -bajo su nombre RENOMBRADO por 2026_09_07_090000- y eso falla o, peor,
-- duplica estructura. La instruccion es explicita: NO asumir que ya estan registradas.
--
-- COMO LEER EL RESULTADO. Cada consulta devuelve una columna `veredicto`. Si alguna dice
-- algo distinto de OK, SE DETIENE EL DESPLIEGUE y se revisa a mano. Ninguna de estas
-- comprobaciones se puede "arreglar" desde aqui.
-- =====================================================================================

\echo '== 1. Identidad de la base, para no correr esto contra la que no es =='
SELECT current_database()  AS base,
       current_schema()    AS esquema,
       version()           AS motor;

-- -------------------------------------------------------------------------------------
-- 2. LAS NUEVE MIGRACIONES NUEVAS: cuales ya estan registradas y cuales se aplicarian.
--
--    Las siete `2026_09_01_1200*` son el riesgo real. Lo que se espera en una base donde
--    la taxonomia ya funciona es que esten REGISTRADAS (corrieron desde la rama supplhi) y
--    por lo tanto `migrate` las omita. Si aparecen como NO REGISTRADA hay que parar: crear
--    `supplier_categories` sobre una base que ya tiene `taxonomy_categories` no es una
--    operacion segura y no la resuelve este despliegue.
-- -------------------------------------------------------------------------------------
\echo '== 2. Migraciones nuevas del candidato: registradas o pendientes =='
WITH nuevas(nombre, clase) AS (
    VALUES
      ('2022_12_02_000000_restore_deferred_foreign_keys',            'nueva de este candidato: reparacion de integridad, idempotente'),
      ('2026_10_09_120000_create_taxonomy_category_requests_table',  'nueva de este candidato: tabla nueva + columna nueva en el singleton'),
      ('2026_09_01_120000_create_supplier_categories_table',         'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120001_create_supplier_category_translations_table','IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120002_create_supplier_category_synonyms_table',  'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120003_create_empresa_supplier_categories_table', 'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120004_create_empresa_catalog_items_table',       'IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120005_create_empresa_catalog_item_category_table','IMPORTADA de supplhi: debe estar REGISTRADA'),
      ('2026_09_01_120006_create_category_mapping_audit_table',      'IMPORTADA de supplhi: debe estar REGISTRADA')
)
SELECT n.nombre,
       n.clase,
       (m.migration IS NOT NULL) AS registrada,
       m.batch,
       CASE
         WHEN n.clase LIKE 'IMPORTADA%' AND m.migration IS NULL
           THEN 'PARAR: migracion CREATE TABLE importada y NO registrada - migrate intentaria crear una tabla que ya existe renombrada'
         WHEN n.clase LIKE 'nueva%' AND m.migration IS NOT NULL
           THEN 'ATENCION: ya registrada; revisar por que antes de volver a aplicarla'
         ELSE 'OK'
       END AS veredicto
FROM nuevas n
LEFT JOIN migrations m ON m.migration = n.nombre
ORDER BY n.nombre;

-- -------------------------------------------------------------------------------------
-- 3. LAS SEIS MIGRACIONES MODIFICADAS tienen que estar YA registradas.
--
--    El candidato las edita (aplazar cuatro claves foraneas, alinear el stub de permisos a
--    la v6 instalada, comprobacion de indice portable). Esas ediciones son INERTES sobre
--    una base donde ya corrieron. Si alguna NO esta registrada, el razonamiento de
--    inocuidad no aplica y hay que parar.
-- -------------------------------------------------------------------------------------
\echo '== 3. Migraciones modificadas: tienen que estar ya registradas =='
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
       CASE WHEN m.migration IS NULL
            THEN 'PARAR: migracion editada que NO corrio aqui - la edicion dejaria de ser inerte'
            ELSE 'OK' END AS veredicto
FROM modificadas d
LEFT JOIN migrations m ON m.migration = d.nombre
ORDER BY d.nombre;

-- -------------------------------------------------------------------------------------
-- 4. OBJETOS QUE LA MIGRACION DE TASK-0010A VA A CREAR: no deben existir todavia.
-- -------------------------------------------------------------------------------------
\echo '== 4. Objetos nuevos de TASK-0010A: no deben existir aun =='
SELECT 'taxonomy_category_requests (tabla)' AS objeto,
       to_regclass('public.taxonomy_category_requests') IS NOT NULL AS existe,
       CASE WHEN to_regclass('public.taxonomy_category_requests') IS NOT NULL
            THEN 'ATENCION: ya existe; comparar su forma con la migracion antes de aplicar'
            ELSE 'OK' END AS veredicto
UNION ALL
SELECT 'taxonomy_selection_settings.category_request_recipient_email (columna)',
       EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = current_schema()
                 AND table_name = 'taxonomy_selection_settings'
                 AND column_name = 'category_request_recipient_email'),
       CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns
                         WHERE table_schema = current_schema()
                           AND table_name = 'taxonomy_selection_settings'
                           AND column_name = 'category_request_recipient_email')
            THEN 'ATENCION: la columna ya existe; `ADD COLUMN IF NOT EXISTS` la omitira'
            ELSE 'OK' END
UNION ALL
-- El singleton tiene que existir: la migracion le agrega una columna, no lo crea.
SELECT 'taxonomy_selection_settings (tabla, prerrequisito)',
       to_regclass('public.taxonomy_selection_settings') IS NOT NULL,
       CASE WHEN to_regclass('public.taxonomy_selection_settings') IS NULL
            THEN 'PARAR: falta el singleton que esta migracion extiende'
            ELSE 'OK' END;

-- -------------------------------------------------------------------------------------
-- 5. LAS CUATRO CLAVES FORANEAS APLAZADAS: estado y acciones referenciales exactas.
--
--    `2022_12_02_000000` solo crea la que falte, y solo acepta como equivalente una que
--    apunte a la MISMA columna referenciada y que NO propague. Aqui se lee exactamente eso
--    para saber de antemano que hara: omitir las cuatro (lo esperado en una base ya
--    migrada) o crear alguna.
--
--    confdeltype/confupdtype: 'a' = NO ACTION, 'r' = RESTRICT, 'c' = CASCADE,
--    'n' = SET NULL, 'd' = SET DEFAULT. Solo 'a' y 'r' cuentan como no propagar.
-- -------------------------------------------------------------------------------------
\echo '== 5. Las cuatro claves foraneas aplazadas: existencia, destino y acciones =='
WITH objetivo(tabla, columna, ref_tabla, ref_columna) AS (
    VALUES
      ('empresas',        'city_id',                    'cities',                  'id'),
      ('sustainabilities','areas_id',                   'areas',                   'id'),
      ('infratypes',      'infrasectors_id',            'infrasectors',            'id'),
      ('infrafacilities', 'infraregion_infrasystem_id', 'infraregion_infrasystem', 'id')
)
SELECT o.tabla,
       o.columna,
       c.conname                                   AS nombre_actual,
       confrelid::regclass::text                   AS referencia_actual,
       c.confdeltype                               AS on_delete,
       c.confupdtype                               AS on_update,
       CASE
         WHEN c.conname IS NULL
           THEN 'SE CREARA: la clave no existe en esta base'
         WHEN confrelid::regclass::text <> o.ref_tabla
           THEN 'PARAR: existe una clave en esa columna pero apunta a otra tabla'
         WHEN c.confdeltype NOT IN ('a','r') OR c.confupdtype NOT IN ('a','r')
           THEN 'PARAR: la clave existente PROPAGA (cascade/set null) y el objetivo no - la migracion levantara excepcion'
         ELSE 'OK: se omitira, ya satisface el objetivo'
       END AS veredicto
FROM objetivo o
LEFT JOIN pg_constraint c
       ON c.contype = 'f'
      AND c.conrelid = to_regclass('public.' || o.tabla)
      AND c.conkey = ARRAY[(SELECT a.attnum FROM pg_attribute a
                            WHERE a.attrelid = to_regclass('public.' || o.tabla)
                              AND a.attname = o.columna)]::smallint[]
ORDER BY o.tabla;

-- -------------------------------------------------------------------------------------
-- 6. PRERREQUISITOS DE ESA MIGRACION: si falta una tabla referenciada, ahora LEVANTA
--    excepcion en vez de seguir en silencio, asi que conviene saberlo antes.
-- -------------------------------------------------------------------------------------
\echo '== 6. Tablas que la reparacion de integridad necesita =='
SELECT t.nombre,
       to_regclass('public.' || t.nombre) IS NOT NULL AS existe,
       CASE WHEN to_regclass('public.' || t.nombre) IS NULL
            THEN 'PARAR: la migracion levantara excepcion por esta tabla ausente'
            ELSE 'OK' END AS veredicto
FROM (VALUES ('empresas'),('cities'),('sustainabilities'),('areas'),
             ('infratypes'),('infrasectors'),('infrafacilities'),('infraregion_infrasystem')) AS t(nombre)
ORDER BY t.nombre;

-- -------------------------------------------------------------------------------------
-- 7. CONTEO DE CONTROL de las tablas gobernadas, para comparar DESPUES del despliegue.
--    Ninguna migracion de este candidato debe cambiar estos numeros: son todas de
--    estructura, ninguna toca datos.
-- -------------------------------------------------------------------------------------
\echo '== 7. Conteo de control de las tablas gobernadas (debe ser identico despues) =='
SELECT 'taxonomy_categories' AS tabla, count(*) AS filas FROM taxonomy_categories
UNION ALL SELECT 'taxonomy_canonical_concepts', count(*) FROM taxonomy_canonical_concepts
UNION ALL SELECT 'taxonomy_term_cpv_relations', count(*) FROM taxonomy_term_cpv_relations
UNION ALL SELECT 'taxonomy_term_concepts', count(*) FROM taxonomy_term_concepts
UNION ALL SELECT 'empresa_taxonomy_category', count(*) FROM empresa_taxonomy_category
UNION ALL SELECT 'empresas', count(*) FROM empresas
ORDER BY tabla;

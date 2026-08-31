<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de ExperienceView (Fase 2).
 *
 * Hay 2 migraciones para esta vista en el historial (2022_11_16 y 2022_12_03) - la definicion
 * efectiva es la del timestamp mas reciente (2022_12_03_003734_create_experience_view.php), que se
 * uso como base. Verificado con SHOW CREATE VIEW ExperienceView: coincide en estructura, pero con
 * el mismo drift ya visto en Machinery/Facility/Inventory - el CASE de `magnitud` en la migracion
 * tiene `ELSE '> 10.000.001 USD'` generico, la definicion real tiene `ELSE NULL`. Se usa la real.
 *
 * *** Bug real de produccion encontrado y replicado a proposito (no corregido) ***: la subconsulta
 * interna tiene `experiences t ... LEFT JOIN experiences ON experiences.empresa_id = t.id` - un
 * self-join de `experiences` contra si misma, comparando `empresa_id` (de la segunda instancia)
 * contra `t.id` (el ID PROPIO de una fila de experiences, NO su empresa_id) - parece un
 * copy-paste-error de otro patron de join, sin sentido semantico. Como no se selecciona ninguna
 * columna de esa segunda instancia, su UNICO efecto observable es multiplicar cada fila cuando el
 * `id` de una experiencia coincide con el `empresa_id` de OTRA fila de experiences (fan-out de
 * join). Confirmado con datos reales (scratchpad diag_experience_selfjoin.php, solo conteos): 21
 * de 93 `experiences.id` coinciden por casualidad con algun `experiences.empresa_id`, y esto
 * produce 13 grupos de filas duplicadas en la vista real de produccion HOY. Como el objetivo de
 * esta fase es paridad exacta con lo que ve el usuario en produccion (no corregir bugs de reporte
 * sin que el cliente lo pida), se replica el mismo fan-out con
 * `generate_series(1, GREATEST(1, <conteo de coincidencias>))`. Se documenta aqui explicitamente
 * para que quede registrado como un hallazgo a discutir con el cliente en otro momento - no es un
 * comportamiento intencional de reporte, es un bug de la consulta original.
 *
 * Igual que en ClientsView, el truco `ROW_NUMBER() OVER()` (usando una tabla NO relacionada -
 * empresas/customers_country - solo como generador de un rango de indices 0..N-1 "casualmente"
 * suficientemente grande) se reemplaza por `jsonb_array_elements` nativo sobre `exp_year`.
 * Verificado que el rango usado por el original (401, cantidad de empresas con customers_country)
 * siempre fue mayor al maximo real de elementos en cualquier `exp_year` (82) - no hay riesgo de que
 * la vista original ya estuviera truncando datos silenciosamente por un rango insuficiente.
 *
 * `services_id` (dentro de `rec.data.services_id`) es un array JSON nativo (no un string
 * JSON-dentro-de-JSON como parecia sugerir el doble `json_extract(json_unquote(...))` del
 * original) - confirmado con datos reales, maximo 10 elementos observados (coincide exactamente
 * con el rango 0-9 del truco `UNION SELECT 0..9` del original: no se puede descartar del todo que
 * el original trunque arrays de mas de 10, pero no se encontro ningun caso real hoy). Se usa
 * `jsonb_array_elements_text` directo sobre el array, sin limite de 10, mas correcto y sin cambiar
 * el resultado observable con los datos actuales.
 *
 * `GROUP_CONCAT(services.name, ' ' SEPARATOR ',')`: MySQL concatena primero `name` + `' '` por
 * fila y luego une las filas con `,` (sin espacio despues de la coma) - se replica con
 * `string_agg(services.name || ' ', ',')`. Sin ORDER BY explicito en el original (orden no
 * garantizado por MySQL); se ordena por `services.id` para tener un resultado determinista y se
 * verifica con db:diff-view si esto genera alguna diferencia de orden (analogo a los casos ya
 * aceptados en catalogoView/ClientsView).
 *
 * Los campos `prof_tech`/`manpower` usan el mismo patron `REPLACE(json_unquote(...), 'null', '')`
 * que `cliente` en ClientsView (gotcha de `json_unquote` sobre JSON null). A diferencia de
 * ClientsView (donde la clave SIEMPRE existe en los datos reales, verificado), aqui SI hay casos
 * reales de clave completamente ausente (las 4 empresas con `exp_year` mal formado, ver nota de
 * abajo) - y `REPLACE(NULL, 'null', '')` en MySQL da NULL (no ''), a diferencia de
 * `REPLACE('null', 'null', '')` que da ''. Un simple `coalesce(elem->>'x','')` NO distingue estos
 * dos casos (ambos llegan a `->>'x'` como SQL NULL) y los aplana incorrectamente a '' en ambos.
 * Se usa un CASE de 3 ramas: clave/ruta ausente (`-> 'x' IS NULL`) -> NULL real; valor JSON null
 * explicito (`jsonb_typeof(...) = 'null'`) -> ''; valor real -> replace normal (por si el string
 * contuviera la subcadena literal "null").
 *
 * `descripcion`, a diferencia de `prof_tech`/`manpower`, NO tiene ningun `REPLACE` en el SQL
 * original - es solo `json_unquote(json_extract(rec,'$.data.Descripcion'))` a secas. Esto importa:
 * cuando el valor de `Descripcion` es JSON null, MySQL deja el artefacto de texto "null" (4
 * caracteres) TAL CUAL en el reporte real (sin limpiar), mientras que `->>` de Postgres convierte
 * un JSON null directamente a SQL NULL (comportamiento distinto y "mas correcto", pero que rompe la
 * paridad). Se usa un CASE explicito con `jsonb_typeof(...) = 'null'` para forzar el mismo string
 * "null" visible que muestra produccion hoy, sin aplicar ningun replace/coalesce en este campo
 * (a proposito, para no confundirlo con el tratamiento de prof_tech/manpower).
 *
 * `exp_year` es `$table->json(...)` de Laravel = tipo `json` en Postgres - se castea `::jsonb`.
 *
 * *** Hallazgo importante para el cliente (bug preexistente en produccion, no una diferencia de
 * traduccion) ***: la columna `service` de MySQL, con su doble
 * `json_extract(json_unquote(json_extract(rec,'$.data.services_id')), concat('$[',idx,']'))`, da
 * **NULL en el 100% de las 973 filas de ExperienceView en produccion hoy** (verificado
 * exhaustivamente, sin ninguna excepcion - scratchpad diag_experience_service2.php). La traduccion
 * directa a Postgres con `jsonb_array_elements_text` SI logra extraer los servicios reales (los
 * datos existen y son validos, ver nota de `services_id` mas abajo) - es decir, la version de
 * Postgres seria "mas correcta" que la de produccion. Se decide, para esta fase, REPLICAR el bug
 * (columna siempre NULL) en vez de corregirlo silenciosamente: el alcance de la Fase 2 es paridad
 * exacta con lo que el usuario ve hoy, no un rediseno de reportes sin que el cliente lo pida. Se
 * deja registrado aqui para discutirlo aparte - el reporte "Experiencia" nunca mostro esta columna.
 *
 * *** Bugs de datos reales encontrados al correr esta migracion (bloqueantes, no cosmeticos) ***:
 *
 * 1) `jsonb_array_elements` fallo con SQLSTATE 22023 ("cannot extract elements from a scalar/objeto")
 *    para 4 de 93 filas de `experiences` (ids 112/113/114/115): su columna `exp_year` NO es un
 *    array JSON sino un OBJETO JSON con una sola clave (un UUID) - dato corrupto/legado, no un
 *    array de experiencias. `json_extract(objeto, '$[idx]')` de MySQL tiene un comportamiento
 *    documentado de auto-wrap: cuando el path usa notacion de array (`$[N]`) sobre un valor que NO
 *    es un array, MySQL lo trata como si estuviera envuelto en un array de un solo elemento -
 *    `$[0]` devuelve el valor COMPLETO (el objeto entero), y cualquier otro indice da NULL.
 *    Confirmado con la vista real: esas 4 empresas tienen exactamente 1 fila en ExperienceView, con
 *    todos los campos `$.data.*` en NULL (porque el objeto real no tiene una clave `data`) - se
 *    replica con un CASE que envuelve el valor en un array de 1 elemento con `jsonb_build_array`
 *    cuando `jsonb_typeof(...)` no es `'array'`.
 *
 * 2) Fallo similar (mismo SQLSTATE) en la extraccion de `services_id` para 8 elementos repartidos en
 *    4 experiencias (ids 9/20/66/92): en esos elementos puntuales, `rec.data.services_id` es
 *    litealmente el valor JSON `null` (no una clave ausente - `jsonb_typeof` confirma el tipo
 *    `'null'`), y `jsonb_array_elements_text` tampoco acepta un escalar JSON null. El primer intento
 *    (`coalesce(x, '[]'::jsonb)`) no alcanza porque `coalesce` solo actua sobre SQL NULL, no sobre
 *    un valor JSON null (que SI es un valor no-NULL para SQL). Se agrega el mismo patron de CASE +
 *    `jsonb_typeof(...) = 'array'` para cubrir ambos casos (clave ausente Y JSON null explicito) de
 *    forma uniforme.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ExperienceView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"ExperienceView\" AS select
                z.empresa_id as id,
                (select empresas.name from empresas where empresas.id = z.empresa_id) as name,
                (select infrasectors.sector_name from infrasectors where infrasectors.id = (z.rec -> 'data' ->> 'infrasectors_id')::int) as sectorind,
                (select infratypes.type_name from infratypes where infratypes.id = (z.rec -> 'data' ->> 'infratypes_id')::int) as tipoind,
                (select infrasystems.system_name from infrasystems where infrasystems.id = (z.rec -> 'data' ->> 'infrasystems_id')::int) as systemind,
                (select infraregions.region_name from infraregions where infraregions.id = (z.rec -> 'data' ->> 'infraregions_id')::int) as regionind,
                (select infrafacilities.facility_name from infrafacilities where infrafacilities.id = (z.rec -> 'data' ->> 'infrafacilities_id')::int) as facilityind,
                (select sectors.name from sectors where sectors.id = (z.rec -> 'data' ->> 'sectors_id')::int) as sector,
                NULL::text as service,
                z.rec -> 'data' ->> 'exp_year' as ano,
                CASE z.rec -> 'data' ->> 'magnitud'
                    WHEN '1' THEN '< 100.000 USD'
                    WHEN '2' THEN '100.001 - 1.000.000 USD'
                    WHEN '3' THEN '1.000.001 - 10.000.000 USD'
                    WHEN '4' THEN '> 10.000.001 USD'
                    ELSE NULL
                END AS magnitud,
                CASE
                    WHEN z.rec -> 'data' -> 'prof_tech' IS NULL THEN NULL
                    WHEN jsonb_typeof(z.rec -> 'data' -> 'prof_tech') = 'null' THEN ''
                    ELSE replace(z.rec -> 'data' ->> 'prof_tech', 'null', '')
                END as prof_tech,
                CASE
                    WHEN z.rec -> 'data' -> 'manpower' IS NULL THEN NULL
                    WHEN jsonb_typeof(z.rec -> 'data' -> 'manpower') = 'null' THEN ''
                    ELSE replace(z.rec -> 'data' ->> 'manpower', 'null', '')
                END as manpower,
                CASE WHEN jsonb_typeof(z.rec -> 'data' -> 'Descripcion') = 'null'
                    THEN 'null'
                    ELSE z.rec -> 'data' ->> 'Descripcion'
                END as descripcion
            from (
                select t.empresa_id as empresa_id, elem as rec
                from experiences t
                cross join lateral jsonb_array_elements(
                    CASE WHEN jsonb_typeof((t.exp_year)::jsonb) = 'array'
                        THEN (t.exp_year)::jsonb
                        ELSE jsonb_build_array((t.exp_year)::jsonb)
                    END
                ) as elem
                cross join lateral generate_series(1, GREATEST(1, (
                    select count(*)::int from experiences e2 where e2.empresa_id = t.id
                ))) as dup(n)
            ) z
            ORDER BY z.empresa_id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ExperienceView"');
    }
};

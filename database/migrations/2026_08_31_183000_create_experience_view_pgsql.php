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
 * *** 1 bug de produccion CORREGIDO a proposito (no replicado), + 1 limpieza de codigo muerto que
 * resulto NO ser un bug real al verificar con datos - decision tomada con el cliente despues de
 * medir con datos reales, no por apariencia:
 *
 * 1) `service` (nombres de servicios de cada experiencia) daba NULL en el 100% de las 973 filas en
 *    produccion, por un doble `json_extract(json_unquote(json_extract(...)))` roto. Verificado con
 *    datos reales antes de corregir: se encontraron 1323 referencias a service_id en 890 elementos
 *    de exp_year, y las 83 IDs unicas referenciadas EXISTEN TODAS en la tabla `services` (0
 *    huerfanos) - confirma que la columna es corregible con datos limpios y fiables, no solo
 *    "distinta". Se implementa con `jsonb_array_elements_text` sobre el array nativo `services_id`.
 *
 * 2) Self-join aparentemente sin sentido: la subconsulta interna original tiene
 *    `experiences t ... LEFT JOIN experiences ON experiences.empresa_id = t.id` - compara
 *    `empresa_id` (de una segunda instancia de experiences) contra `t.id` (el ID PROPIO de una fila
 *    de experiences, no su empresa_id). A primera vista parecia un bug de fan-out (multiplicar
 *    filas cuando el `id` de una experiencia coincide con el `empresa_id` de otra) - la
 *    investigacion inicial encontro 21 `experiences.id` que SI coinciden con algun
 *    `experiences.empresa_id`, y asumio que eso causaba duplicados. Verificacion mas profunda
 *    (antes de aplicar el "fix") demostro que esa suposicion era INCORRECTA:
 *    `experiences.empresa_id` es UNICO (93 filas, 93 empresa_id distintos, confirmado), asi que el
 *    self-join NUNCA produce mas de 1 fila coincidente por `t.id` - `GREATEST(1, COUNT(...))` da
 *    SIEMPRE 1, sin fan-out real con los datos actuales. Los "duplicados" que parecian confirmar el
 *    bug (20 filas con el mismo ano+descripcion+sector) resultaron ser, en su mayoria, entradas
 *    DISTINTAS que coinciden en esos 3 campos por coincidencia de negocio (mismo ano/sector/
 *    descripcion pero otros campos distintos) - solo 2 empresas (5 elementos) tienen entradas
 *    REALMENTE identicas dentro de su propio array `exp_year`, y eso es un dato de origen (alguien
 *    cargo la misma experiencia dos veces), no algo que esta vista deba "limpiar" silenciosamente.
 *    Conclusion: el self-join es codigo muerto sin ningun efecto observable hoy (confirmado con una
 *    comparacion de multiset completa excluyendo solo la columna `service`: 0 diferencias con o sin
 *    el join) - se elimina igual, por claridad/mantenibilidad, no porque corrigiera un bug real.
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
 * con el rango 0-9 del truco `UNION SELECT 0..9` del original, aunque no hizo falta replicar ese
 * limite: `jsonb_array_elements_text` no tiene tope). Se manejan 2 casos especiales (ver bugs de
 * datos abajo): clave ausente y valor JSON null explicito, ambos tratados como "sin servicios".
 *
 * `GROUP_CONCAT(services.name, ' ' SEPARATOR ',')`: MySQL concatena primero `name` + `' '` por
 * fila y luego une las filas con `,` (sin espacio despues de la coma) - se replica con
 * `string_agg(services.name || ' ', ',')`. Sin ORDER BY explicito en el original (orden no
 * garantizado por MySQL); se ordena por `services.id` para tener un resultado determinista.
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
 * original - es solo `json_unquote(json_extract(rec,'$.data.Descripcion'))` a secas. Por eso,
 * cuando el valor de `Descripcion` es JSON null, MySQL deja el artefacto de texto "null" (4
 * caracteres) TAL CUAL visible en el reporte real, en vez de una celda vacia - confirmado en 18 de
 * 973 filas. *** CORREGIDO a proposito (no replicado) ***: se decidio, junto con el cliente, NO
 * reproducir este artefacto - un usuario viendo "null" como descripcion de una experiencia es
 * confuso y menos fiable que una celda vacia, sin ninguna razon de negocio para preservarlo (a
 * diferencia de los otros 2 bugs de esta vista, este no cambia ningun conteo ni cardinalidad, solo
 * el texto mostrado). Se trata igual que `prof_tech`/`manpower`: JSON null explicito -> '' en vez
 * de dejar el texto "null".
 *
 * `exp_year` es `$table->json(...)` de Laravel = tipo `json` en Postgres - se castea `::jsonb`.
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
 *    `'null'`), y `jsonb_array_elements_text` tampoco acepta un escalar JSON null. Se usa un CASE +
 *    `jsonb_typeof(...) = 'array'` para cubrir ambos casos (clave ausente Y JSON null explicito) de
 *    forma uniforme, tratando ambos como "arreglo vacio" (sin servicios).
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
                (
                    select string_agg(services.name || ' ', ',' ORDER BY services.id) from services
                    where services.id in (
                        select (v)::int from jsonb_array_elements_text(
                            CASE WHEN jsonb_typeof(coalesce(z.rec -> 'data' -> 'services_id', 'null'::jsonb)) = 'array'
                                THEN z.rec -> 'data' -> 'services_id'
                                ELSE '[]'::jsonb
                            END
                        ) as v
                    )
                ) as service,
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
                    THEN ''
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
            ) z
            ORDER BY z.empresa_id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ExperienceView"');
    }
};

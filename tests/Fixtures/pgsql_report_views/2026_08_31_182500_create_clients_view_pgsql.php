<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de ClientsView (Fase 2).
 *
 * Verificado con SHOW CREATE VIEW ClientsView contra MySQL de produccion: coincide con
 * database/migrations_mysql_only_views/2022_11_13_181946_create_clients_view.php, sin drift.
 *
 * Esta es la vista que usa el truco `ROW_NUMBER() OVER()` mencionado en el plan como "no conviene
 * traducir literalmente". El original genera una secuencia `idx` = 0..N-1 donde N = la cantidad de
 * empresas DISTINTAS que tienen al menos un elemento en `customers_country` (un numero
 * "casualmente" siempre >= el tamano maximo de cualquier array individual, usado solo como fuente
 * de indices 0..N-1), cruza esa secuencia con TODAS las empresas y filtra con
 * `JSON_EXTRACT(customers_country, '$[idx]') IS NOT NULL` para quedarse solo con los indices que
 * realmente existen en el array de cada empresa - una forma indirecta y fragil de "explotar" un
 * array JSON por posicion. Se reemplaza con `jsonb_array_elements`, que hace exactamente eso de
 * forma nativa y sin trucos de indices.
 *
 * `REPLACE(json_unquote(json_extract(rec, '$.customer_name')), 'null', '')`: gotcha real de MySQL
 * confirmado en la documentacion y con datos reales (customer_name siempre string, nunca JSON null,
 * en los 2106 elementos de customers_country de produccion) - `json_unquote()` sobre un valor JSON
 * `null` (no SQL NULL) devuelve literalmente el string de 4 caracteres "null", no NULL. El REPLACE
 * limpia ese artefacto. Postgres NO tiene este problema: `elem ->> 'customer_name'` ya devuelve
 * SQL NULL tanto para clave ausente como para valor JSON null. Para igualar el resultado visible
 * (MySQL muestra '' en ese caso, no NULL) se usa `coalesce(elem ->> 'customer_name', '')`. Se
 * mantiene ademas el `replace(..., 'null', '')` sobre el resultado ya coalescido, por si algun
 * nombre real contuviera la subcadena literal "null" (el REPLACE de MySQL es una sustitucion ciega
 * de substring, no condicionada al origen del valor) - no se encontro ningun caso real hoy, pero
 * replica el comportamiento exacto en vez de asumir que nunca pasara.
 *
 * `(SELECT countries.country_name FROM countries WHERE countries.id = json_unquote(json_extract(rec,
 * '$.country_id')))`: `country_id` en los datos reales es a veces string numerico (1821), a veces
 * JSON null (271 - clave presente con valor null) y a veces numero JSON crudo (14) - nunca no-
 * numerico ni con decimales (verificado). Se traduce con `(elem ->> 'country_id')::int` - para
 * country_id NULL, `->>` da SQL NULL, el cast a int de NULL es NULL, y la comparacion
 * `countries.id = NULL` no matchea nada (subquery da NULL) - mismo resultado final que MySQL
 * (donde el artefacto "null" tampoco matchea ningun id real de pais), aunque el mecanismo interno
 * sea distinto.
 *
 * El "Sector" (`GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ')`, sin ORDER BY explicito en el
 * original) se traduce a `string_agg(DISTINCT sectors.name, ', ' ORDER BY sectors.name)` -
 * Postgres exige un ORDER BY cuando se usa DISTINCT en un agregado; se ordena alfabeticamente. Si
 * db:diff-view mostrara diferencias de ORDEN (no de contenido) en esta columna para empresas con
 * multiples sectores, seria un caso analogo al ya aceptado en catalogoView (el orden real de
 * MySQL sin ORDER BY explicito no esta garantizado por el estandar SQL).
 *
 * `customers_country` es `$table->json(...)` de Laravel = tipo `json` en Postgres - se castea con
 * `::jsonb` antes de `jsonb_array_elements`. Al no llevar filtro extra en el LATERAL (a diferencia
 * de Resource/Machinery/Facility que necesitaban `ON true` porque jsonb_to_recordset con columnas
 * tipadas puede fallar en cast), un simple `CROSS JOIN LATERAL jsonb_array_elements(...)` ya excluye
 * automaticamente las empresas con `customers_country` NULL o `'[]'` (0 filas generadas), igual que
 * el INNER JOIN original de MySQL.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ClientsView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"ClientsView\" AS select
                t.id as id,
                t.name as name,
                (
                    SELECT string_agg(DISTINCT sectors.name, ', ' ORDER BY sectors.name)
                    FROM empresas
                    LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
                    LEFT JOIN services ON empresa_sector_service.service_id = services.id
                    LEFT JOIN sectors ON services.sectors_id = sectors.id
                    WHERE empresas.id = t.id
                ) AS \"Sector\",
                replace(coalesce(elem ->> 'customer_name', ''), 'null', '') AS cliente,
                (
                    SELECT countries.country_name FROM countries
                    WHERE countries.id = (elem ->> 'country_id')::int
                ) AS pais
            from empresas t
            cross join lateral jsonb_array_elements((t.customers_country)::jsonb) AS elem
            ORDER BY t.id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ClientsView"');
    }
};

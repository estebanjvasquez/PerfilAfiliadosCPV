<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de ManagementDetView (Fase 2).
 *
 * Verificado con SHOW CREATE VIEW ManagementDetView contra MySQL de produccion: a diferencia de
 * catalogoView, esta migracion (database/migrations_mysql_only_views/2023_06_02_204233_create_management_det_view.php)
 * SI coincide con la definicion real - no hubo drift aqui.
 *
 * Las 16 columnas booleanas de esta tabla (`iso9001`, `quality_otros`, etc.) se comparan en MySQL
 * con `= 1` (tinyint), pero en Postgres son `boolean` real (`$table->boolean(...)` mapea asi en
 * pgsql) - Postgres no permite comparar boolean con el entero 1 (error de tipo). Se traduce cada
 * comparacion a `IS TRUE`, igual que en ManagementView.
 *
 * Riesgo de ambiguedad senalado en el plan, resuelto con datos reales antes de escribir esta vista
 * (ver scripts en scratchpad diag_json_length.php / diag_json_format*.php, solo conteos y
 * longitudes, sin imprimir el contenido real de los certificados):
 *
 * `json_unquote(json_extract(a.quality_data, '$[*].quality_otros_name'))` (y las 5 columnas
 * analogas: environment/credibility/security/pmi/info) usan un path con wildcard `$[*]`. Contrario
 * a la intuicion de que json_unquote() "desenvuelve" el valor a texto plano, MySQL NO devuelve un
 * escalar aqui: como el path tiene wildcard, json_extract siempre devuelve un array JSON (incluso
 * con un solo elemento) y json_unquote() no le hace nada a un array (solo "desenvuelve" JSON strings
 * puros) - el resultado final es la serializacion JSON del array como texto, corchetes y comillas
 * incluidas. Confirmado con datos reales: 1 elemento -> `["valor"]`, 3 elementos ->
 * `["v1", "v2", "v3"]` (separador coma+espacio, verificado byte a byte). Con 0 elementos, el
 * wildcard no encuentra nada que extraer y MySQL devuelve NULL (no la cadena "[]") - confirmado con
 * una fila real de `quality_data = '[]'`.
 *
 * Nota (encontrada al correr esta migracion): las columnas `*_data` de `management` se crearon con
 * `$table->json(...)`, que en Postgres da el tipo `json`, NO `jsonb` - `jsonb_array_elements` no
 * acepta `json` directamente, hace falta castear con `::jsonb` primero. Se corrige aqui.
 *
 * Se replica el array con `jsonb_array_elements(...) WITH ORDINALITY` (preserva el orden original,
 * igual que el recorrido de `$[*]`) + `to_json(elem->>'campo')` + `string_agg(..., ', ' ORDER BY
 * ordinalidad)` + concatenar '[' y ']' con `||`. Con 0 elementos, `jsonb_array_elements` no produce
 * filas, `string_agg` da NULL, y `||` (que propaga NULL, a diferencia de concat()) deja el resultado
 * final en NULL - igual que MySQL, sin CASE aparte.
 *
 * *** Intento fallido a documentar (leccion para las vistas que siguen) ***: la primera corrida de
 * db:diff-view mostro 4/98 filas con diferencia en columnas *_OTROS. Diagnostico con hex de las
 * primeras 8-14 posiciones de cada mismatch (ver scratchpad diag_managementdet.php): en esas filas,
 * MySQL mostraba literalmente la secuencia de 6 caracteres ASCII `\u00XX` (ej. `Í` para la
 * letra 'Í') donde Postgres mostraba el caracter UTF-8 crudo. La hipotesis inicial (equivocada) fue
 * que MySQL "escapa" activamente todo caracter no-ASCII en sus funciones JSON, y se escribio una
 * funcion Postgres (`mysql_json_unicode_escape`, ya eliminada) para replicar ese escapado.
 * Al aplicarla, el diff EMPEORO (7/98 en vez de 4/98) - la hipotesis estaba mal. Diagnostico mas
 * profundo (diag_raw_json_storage*.php, solo conteos/booleanos, sin imprimir contenido real):
 * el JSON ALMACENADO en produccion ya trae, para exactamente esas 4 empresas (513, 265, 561, 195),
 * la secuencia de escape `\u00XX` guardada COMO TEXTO LITERAL dentro del JSON (probablemente de
 * cuando se guardo ese registro con `json_encode()` de PHP SIN el flag `JSON_UNESCAPED_UNICODE`,
 * mientras que el resto de los registros se guardo con codificacion UTF-8 cruda). No es un
 * comportamiento de MySQL ni de Postgres: ambos motores solo pasan a traves el string tal como esta
 * guardado - MySQL no decodifica ese `\u00XX` de vuelta al caracter real al extraerlo de un array
 * JSON (json_unquote no toca arrays), mientras que Postgres SI decodifica cualquier escape JSON
 * valido al parsear el `jsonb` (asi haya sido escrito como `Í` o como el caracter crudo, el
 * valor logico que jsonb guarda y devuelve via `->>` es identico: el caracter real 'Í').
 *
 * Diferencia esperada y aceptada (4 de 98 filas, columnas QUALITY_OTROS/ENVIRONMENT_OTROS/
 * CREDIBILITY_OTROS/SECURITY_OTROS de las empresas 513, 265, 561, 195): el reporte de Postgres
 * muestra el texto correctamente decodificado (ej. "POLÍTICA"), mientras que el reporte de MySQL
 * expone tal cual el artefacto de codificacion doble de esos 4 registros historicos (ej.
 * "POLÍTICA", con la secuencia de escape visible como texto). Se prioriza la version de
 * Postgres (semanticamente correcta) en vez de replicar un bug de datos preexistente que ademas
 * ya se ve roto en los reportes actuales de MySQL - confirmado que son EXACTAMENTE esas 4 empresas
 * buscando la subcadena literal '\u00' en las columnas JSON crudas de `management` en produccion,
 * sin ninguna otra fila afectada.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ManagementDetView"');
        DB::connection('pgsql')->statement('
            CREATE VIEW "ManagementDetView" AS select
                a.empresa_id as id,
                empresas.name as name,
                CASE WHEN a.iso9001 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso9001,
                CASE WHEN a.iso17025 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso17025,
                CASE WHEN a.quality_otros IS TRUE THEN (
                    SELECT \'[\' || string_agg(to_json(elem ->> \'quality_otros_name\')::text, \', \' ORDER BY ord) || \']\'
                    FROM jsonb_array_elements((a.quality_data)::jsonb) WITH ORDINALITY AS t(elem, ord)
                ) ELSE \'NO\' END AS "QUALITY_OTROS",
                CASE WHEN a.iso14001 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso14001,
                CASE WHEN a.iso50001 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso50001,
                CASE WHEN a.environment_otros IS TRUE THEN (
                    SELECT \'[\' || string_agg(to_json(elem ->> \'environment_otros_name\')::text, \', \' ORDER BY ord) || \']\'
                    FROM jsonb_array_elements((a.environment_data)::jsonb) WITH ORDINALITY AS t(elem, ord)
                ) ELSE \'NO\' END AS "ENVIRONMENT_OTROS",
                CASE WHEN a.dun IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS dun,
                CASE WHEN a.iso37001 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso37001,
                CASE WHEN a.credibility_otros IS TRUE THEN (
                    SELECT \'[\' || string_agg(to_json(elem ->> \'credibility_otros_name\')::text, \', \' ORDER BY ord) || \']\'
                    FROM jsonb_array_elements((a.credibility_data)::jsonb) WITH ORDINALITY AS t(elem, ord)
                ) ELSE \'NO\' END AS "CREDIBILITY_OTROS",
                CASE WHEN a.iso45001 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso45001,
                CASE WHEN a.ovid IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS ovid,
                CASE WHEN a.security_otros IS TRUE THEN (
                    SELECT \'[\' || string_agg(to_json(elem ->> \'security_otros_name\')::text, \', \' ORDER BY ord) || \']\'
                    FROM jsonb_array_elements((a.security_data)::jsonb) WITH ORDINALITY AS t(elem, ord)
                ) ELSE \'NO\' END AS "SECURITY_OTROS",
                CASE WHEN a.pmi IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS pmi,
                CASE WHEN a.pmi_otros IS TRUE THEN (
                    SELECT \'[\' || string_agg(to_json(elem ->> \'pmi_otros_name\')::text, \', \' ORDER BY ord) || \']\'
                    FROM jsonb_array_elements((a.pmi_data)::jsonb) WITH ORDINALITY AS t(elem, ord)
                ) ELSE \'NO\' END AS "PMI_OTROS",
                CASE WHEN a.iso27001 IS TRUE THEN \'SÍ\' ELSE \'NO\' END AS iso27001,
                CASE WHEN a.info_otros IS TRUE THEN (
                    SELECT \'[\' || string_agg(to_json(elem ->> \'info_otros_name\')::text, \', \' ORDER BY ord) || \']\'
                    FROM jsonb_array_elements((a.info_data)::jsonb) WITH ORDINALITY AS t(elem, ord)
                ) ELSE \'NO\' END AS "INFO_OTROS"
            from management a
            join empresas on a.empresa_id = empresas.id
        ');
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ManagementDetView"');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de catalogoView (Fase 2).
 *
 * IMPORTANTE: NO se baso en database/migrations_mysql_only_views/2022_10_19_213831_create_catalogo_view.php
 * - ese archivo esta desactualizado. Se confirmo con `SHOW CREATE VIEW catalogoView` contra MySQL
 * de produccion que la vista real fue editada directamente en la base en algun momento (drift),
 * sin actualizar la migracion. Esta reescritura se hizo contra la definicion REAL, verificada con
 * db:diff-view fila por fila. Moraleja para el resto de las vistas de esta fase: siempre confirmar
 * con SHOW CREATE VIEW antes de traducir, no confiar en el archivo de migracion.
 *
 * Traducciones puntuales (no mecanicas):
 * - alias con comillas simples ('fundacion') es sintaxis de MySQL para identificador; en Postgres
 *   las comillas simples son solo para strings literales - se cambia a un alias sin comillas.
 * - CIUDAD = concat(cities.city_name, ', ', countries.country_name): el CONCAT() de Postgres NO
 *   propaga NULL (trata NULL como cadena vacia), a diferencia de MySQL. Se usa || en su lugar
 *   (si propaga NULL, igual que MySQL - empresas sin ciudad/pais deben dar NULL, no ", Venezuela").
 * - Sector/camara: `group_concat(distinct coalesce(x.name,''), ... order by x.name separator ', ')`
 *   -> `string_agg(DISTINCT coalesce(x.name,''), ', ' ORDER BY coalesce(x.name,''))`. Se ordena por
 *   la MISMA expresion coalescida (Postgres exige que el ORDER BY de un agregado con DISTINCT sea
 *   una de sus expresiones agregadas, no una columna suelta distinta).
 * - CONTACTOS (el caso mas delicado, con 2 intentos fallidos antes de este - documentado porque no
 *   es obvio): la consulta real hace 4 LEFT JOIN encadenados (sectores, servicios, contactos,
 *   camaras) sobre la misma empresa - si una empresa tiene, por ejemplo, 2 sectores y 3 camaras,
 *   cada contacto suyo aparece 2x3=6 veces en las filas antes del GROUP BY (fan-out clasico de
 *   joins multiples). El DISTINCT de GROUP_CONCAT en MySQL no es defensivo: es lo que deduplica
 *   ese fan-out. Se saca CONTACTOS del join grande por completo y se calcula en una subconsulta
 *   correlacionada por empresa, con su propio "SELECT DISTINCT" a nivel de fila (no de agregado) -
 *   asi no hay fan-out que deduplicar y se puede ordenar por "name" sin la restriccion de Postgres.
 *   El formato de cada contacto en produccion es
 *   `(nombre, posicion, telefono, email)` con cada campo opcional omitido si es NULL - se replica
 *   con `coalesce(', ' || campo, '')` encadenado (concat() de Postgres no sirve aca por la misma
 *   razon que en CIUDAD: no propaga NULL, y esta logica depende de que SI lo haga).
 * - separador '; \n': MySQL interpreta \n dentro de comillas simples normales; Postgres necesita
 *   el prefijo E'...' (escape string) para que \n sea un salto de linea real.
 * - ORDER BY lower(unaccent(contact_name)): la collation por defecto de MySQL (utf8mb4_unicode_ci)
 *   no distingue mayusculas/minusculas NI acentos al ordenar (compara "Jose" y "José" como
 *   iguales); la de Postgres distingue ambas cosas. Se normaliza con lower()+unaccent() (extension
 *   contrib estandar de Postgres, se habilita en esta misma migracion) antes de ordenar, para
 *   igualar el orden real de MySQL cuando una empresa tiene varios contactos (detectado con
 *   db:diff-view: mismo contenido exacto, mismo largo, orden distinto en 3 de 402 empresas - los
 *   nombres con acentos son comunes en este dataset).
 *
 * - coalesce(..., '()') envolviendo toda la subconsulta: la mayoria de los mismatches detectados
 *   (128 de 130) resultaron ser empresas con CERO contactos vinculados en contact_empresa, no un
 *   problema de las 5 filas excluidas en Fase 1. En la consulta real de MySQL, el LEFT JOIN a
 *   contact_empresa/contacts a nivel de la consulta grande produce, para una empresa sin ningun
 *   contacto, una fila "fantasma" con todos los campos de contacts en NULL - que la formula
 *   coalesce-pesada convierte en el string "()" (no NULL). La subconsulta correlacionada usa INNER
 *   JOIN dentro de un SELECT aparte: si no hay contact_empresa para esa empresa, devuelve CERO
 *   filas, y string_agg sobre cero filas da NULL, no "()". Se envuelve en coalesce(..., '()') para
 *   igualar el comportamiento real de produccion (aunque NULL seria semanticamente mas correcto
 *   para "sin contactos" - se prioriza la paridad exacta en esta fase, no rediseñar el reporte).
 *
 * Diferencia esperada y aceptada #2 (3 de 402 empresas, contenido identico, solo cambia el orden
 * interno de 2 contactos): en esas 3 empresas hay exactamente un par de contactos con el mismo
 * nombre normalizado (mismo nombre salvo mayusculas/acentos) - la consulta original de MySQL
 * ordena solo por "contacts.name", sin ningun desempate para nombres iguales, asi que el orden
 * entre ese par queda indefinido (depende del plan de ejecucion de MySQL, no esta garantizado ni
 * documentado). Se agrego "contact_id" como desempate estable en Postgres para que el resultado
 * sea reproducible de ahora en mas, en vez de perseguir un empate arbitrario de MySQL que ni la
 * propia consulta original garantiza que se mantenga igual entre corridas.
 *
 * Diferencia esperada y aceptada #1, distinta de la de arriba (solo 2 de 402 empresas): las 5 filas
 * de 'contacts' con 'name' vacio excluidas a proposito en la Fase 1 (ver SyncMysqlToPgsql) siguen
 * existiendo en MySQL de produccion. Una empresa vinculada a alguna de esas 5 va a mostrar esa
 * entrada como "()" en MySQL (o restarle ese entry a la lista) pero no en Postgres, porque esa fila
 * de contacto directamente no existe ahi. Confirmado contando cuantas empresas estan realmente
 * vinculadas a esos 5 IDs (2) antes de aceptarlo como conocido, no solo por la forma del texto.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        // Necesaria para ordenar contactos ignorando acentos, igual que la collation por
        // defecto de MySQL (utf8mb4_unicode_ci, insensible a mayusculas Y acentos) - ver nota
        // sobre "ORDER BY lower(unaccent(...))" mas abajo.
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "catalogoView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"catalogoView\" AS select
            empresas.id as id,
            empresas.name,
            string_agg(DISTINCT coalesce(sectors.name, ''), ', ' ORDER BY coalesce(sectors.name, '')) as \"Sector\",
            empresas.street,
            cities.city_name || ', ' || countries.country_name as \"CIUDAD\",
            empresas.ano_fund as fundacion,
            empresas.rif as rif,
            empresas.phone as phone,
            empresas.website as website,

            coalesce((select string_agg(entry, E'; \\n' ORDER BY lower(unaccent(contact_name)), contact_id) from (
                select distinct contacts.id as contact_id, contacts.name as contact_name,
                    '(' || coalesce(contacts.name, '')
                        || coalesce(', ' || contacts.position, '')
                        || coalesce(', ' || contacts.phone, '')
                        || coalesce(', ' || contacts.email, '')
                        || ')' as entry
                from contact_empresa
                join contacts on contact_empresa.contact_id = contacts.id
                where contact_empresa.empresa_id = empresas.id
            ) distinct_contacts), '()') as \"CONTACTOS\",

            string_agg(DISTINCT coalesce(chambers.name, ''), ', ' ORDER BY coalesce(chambers.name, '')) as camara

            from
                empresas

            inner join empresa_user on empresas.id = empresa_user.empresa_id
            left join cities on empresas.city_id = cities.id
            left join countries on cities.country_id = countries.id

            left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
            left join services on empresa_sector_service.service_id = services.id
            left join sectors on services.sectors_id = sectors.id

            left join chamber_empresa on empresas.id = chamber_empresa.empresa_id
            left join chambers on chamber_empresa.chamber_id = chambers.id

            GROUP BY 1, 2, 4, 5, 6, 7, 8, 9
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "catalogoView"');
    }
};

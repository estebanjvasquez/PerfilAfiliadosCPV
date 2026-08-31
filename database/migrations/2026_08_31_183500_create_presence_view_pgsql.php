<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de PresenceView (Fase 2).
 *
 * Verificado con SHOW CREATE VIEW PresenceView contra MySQL de produccion: coincide con
 * database/migrations_mysql_only_views/2023_09_15_225003_create_presence_view.php, sin drift.
 *
 * Patron distinto a los demas: en vez de explotar UN array JSON, empareja por POSICION dos arrays
 * paralelos de la misma fila de `presences` - `office_data[idx]` (como `rec`) y
 * `experience_data[idx]` (como `recx`) - usando el mismo `idx` del truco `ROW_NUMBER() OVER()`
 * (nuevamente sobre `empresas`/`customers_country`, no relacionado, solo como generador de un
 * rango de indices; confirmado suficiente: rango 401 vs maximo real de 10 elementos en ambos
 * arrays). El filtro solo exige que `office_data[idx]` exista; `experience_data[idx]` puede salir
 * NULL si ese array es mas corto, sin excluir la fila (evaluado por posicion, no por existencia
 * independiente). Se replica con `jsonb_array_elements(office_data) WITH ORDINALITY` (ordinalidad
 * 1-based) para `rec`, y `experience_data -> (ordinalidad - 1)` (el operador `->` de Postgres
 * indexa arrays jsonb 0-based directamente, y da NULL sin error si el indice no existe o si
 * `experience_data` es NULL) para `recx` - sin necesidad de explotar `experience_data` por
 * separado.
 *
 * `has_offices`/`has_experience` son `$table->boolean(...)` de Laravel = `boolean` real en
 * Postgres (no tinyint) - `= 0` se traduce a `IS FALSE` (mismo patron que ManagementView/
 * ManagementDetView).
 *
 * `role`/`executed_q`: el CASE original compara `json_unquote(json_extract(recx,'$.role'))`
 * (texto) contra LITERALES ENTEROS (`= 1`, `= 2`, `= 3`) - MySQL coacciona el texto a numero
 * implicitamente. Los valores reales son strings numericos ("1"/"2"/"3", confirmado, sin
 * decimales) - se compara como texto contra '1'/'2'/'3' en Postgres, funcionalmente equivalente
 * (una clave ausente o un valor JSON null nunca matchean ninguna rama y caen al ELSE '' en ambos
 * motores, sin necesitar distinguir esos 2 casos aqui).
 *
 * `mts`/`emp_q`/`expemployees`/`clients`: tienen un COALESCE EXTERIOR envolviendo el
 * REPLACE(json_unquote(...),'null',''), a diferencia de `prof_tech`/`manpower` en ExperienceView
 * (que NO tenian ese coalesce exterior). Gracias a ese coalesce extra, tanto "clave ausente" como
 * "valor JSON null" terminan dando '' en MySQL (se verifico analiticamente que ambos casos
 * convergen al mismo resultado final) - un simple `replace(coalesce(rec->>'x',''),'null','')` en
 * Postgres basta, sin necesitar el CASE de 3 ramas que si hizo falta en ExperienceView.
 *
 * `proj_q` es distinto: solo tiene `COALESCE(json_unquote(...), '')`, SIN REPLACE. Aqui "clave
 * ausente" (SQL NULL -> coalesce a '') y "valor JSON null" (texto literal "null" -> coalesce no
 * actua, queda "null" visible) NO convergen - mismo caso que `descripcion` en ExperienceView. Se
 * usa el mismo CASE de 3 ramas (ausente -> NULL/coalescido a ''; JSON null explicito -> queda como
 * el texto "null"; valor real -> tal cual) para replicar exacto.
 *
 * `office_data`/`experience_data` son `$table->json(...)` de Laravel = tipo `json` en Postgres -
 * se castea `::jsonb`.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "PresenceView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"PresenceView\" AS select
                p.empresa_id AS id,
                COALESCE(e.name, '') AS name,
                CASE WHEN p.has_offices IS FALSE THEN 'X' ELSE ' ' END AS \"hasOfficesNo\",
                CASE WHEN p.has_offices IS FALSE THEN ' ' ELSE 'X' END AS \"hasOfficesYes\",
                COALESCE(c.country_name, '') AS pais,
                replace(coalesce(p.rec ->> 'offices_surf', ''), 'null', '') AS mts,
                replace(coalesce(p.rec ->> 'employees_q', ''), 'null', '') AS \"emp_q\",
                CASE WHEN (p.rec ->> 'status') = 'true' THEN 'SÍ' ELSE 'NO' END AS activa,
                CASE WHEN p.has_experience IS FALSE THEN 'X' ELSE ' ' END AS \"hasExperienceNo\",
                CASE WHEN p.has_experience IS FALSE THEN ' ' ELSE 'X' END AS \"hasExperienceYes\",
                COALESCE(c_exp.country_name, '') AS paisx,
                CASE
                    WHEN p.recx -> 'projects_q' IS NULL THEN ''
                    WHEN jsonb_typeof(p.recx -> 'projects_q') = 'null' THEN 'null'
                    ELSE p.recx ->> 'projects_q'
                END AS \"proj_q\",
                CASE
                    WHEN (p.recx ->> 'role') = '1' THEN 'Subcontratista'
                    WHEN (p.recx ->> 'role') = '2' THEN 'Contratista Principal'
                    WHEN (p.recx ->> 'role') = '3' THEN 'Ambos'
                    ELSE ''
                END AS role,
                CASE
                    WHEN (p.recx ->> 'executed_q') = '1' THEN '< 100.000 USD'
                    WHEN (p.recx ->> 'executed_q') = '2' THEN '100.000 - 1.000.000 USD'
                    WHEN (p.recx ->> 'executed_q') = '3' THEN '1.000.001 - 10.000.000 USD'
                    ELSE ''
                END AS montox,
                replace(coalesce(p.recx ->> 'expemployees_q', ''), 'null', '') AS expemployees,
                replace(coalesce(p.recx ->> 'main_clients', ''), 'null', '') AS clients
            from (
                select
                    t.empresa_id as empresa_id,
                    t.has_offices as has_offices,
                    t.has_experience as has_experience,
                    o.rec as rec,
                    ((t.experience_data)::jsonb -> (o.ord - 1)::int) as recx
                from presences t
                cross join lateral jsonb_array_elements((t.office_data)::jsonb) with ordinality as o(rec, ord)
            ) p
            LEFT JOIN empresas e ON e.id = p.empresa_id
            LEFT JOIN countries c ON c.id = (p.rec ->> 'country_id')::int
            LEFT JOIN countries c_exp ON c_exp.id = (p.recx ->> 'expcountry_id')::int
            ORDER BY p.empresa_id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "PresenceView"');
    }
};

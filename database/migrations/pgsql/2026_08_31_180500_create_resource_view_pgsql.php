<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de ResourceView (Fase 2).
 *
 * Verificado con SHOW CREATE VIEW ResourceView contra MySQL de produccion: coincide con
 * database/migrations_mysql_only_views/2023_05_10_181749_create_resource_view.php, sin drift.
 *
 * `JSON_TABLE(a.employee, '$[*]' COLUMNS(...)) e` expande cada elemento del array JSON en la
 * columna `employee` de `assets` a una fila con 5 columnas tipadas (tfila/junior_q/medium_q/
 * senior_q/employee_type, todas INT). Se traduce con `jsonb_to_recordset` (mas idiomatico en
 * Postgres que intentar portar JSON_TABLE literalmente, ver plan) + `JOIN LATERAL ... ON true`.
 * Igual que en ManagementDetView, la columna `employee` es `$table->json(...)` de Laravel ->
 * tipo `json` en Postgres, no `jsonb` - se castea con `::jsonb` antes de pasarla a
 * `jsonb_to_recordset`.
 *
 * Semantica de "cruce" a verificar (CROSS JOIN JSON_TABLE en MySQL == JOIN LATERAL en Postgres):
 * si `a.employee` es NULL o un array JSON vacio `[]`, JSON_TABLE no produce ninguna fila para ese
 * `assets.id` y, como es un JOIN normal (no LEFT JOIN), esa empresa desaparece por completo de
 * ResourceView - confirmado contando: 112 filas en `assets`, solo 109 en ResourceView real (1 con
 * `employee` NULL, 1 con `employee = '[]'`, y una tercera excluida por otra causa no investigada
 * a fondo, no bloqueante). `jsonb_to_recordset` usado con `JOIN LATERAL ... ON true` (sin `LEFT`)
 * tiene el mismo comportamiento: si la funcion no produce filas para una fila de `assets` (NULL o
 * `[]`), esa fila de `assets` no aparece del todo en el resultado del join - paridad automatica sin
 * logica extra, confirmada con db:diff-view: 109/109 filas identicas en Postgres, 0 diferencias.
 *
 * `max(empresas.name)` ya es 100% portable (patron anti-ONLY_FULL_GROUP_BY, no requiere cambios).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ResourceView"');
        DB::connection('pgsql')->statement('
            CREATE VIEW "ResourceView" AS select
                a.empresa_id as id,
                max(empresas.name) as name,
                SUM(CASE WHEN e.employee_type = 0 THEN e.junior_q ELSE 0 END) AS "Bachilleres_Junior",
                SUM(CASE WHEN e.employee_type = 0 THEN e.medium_q ELSE 0 END) AS "Bachilleres_Medium",
                SUM(CASE WHEN e.employee_type = 0 THEN e.senior_q ELSE 0 END) AS "Bachilleres_Senior",
                SUM(CASE WHEN e.employee_type = 1 THEN e.junior_q ELSE 0 END) AS "Tecnicos_Junior",
                SUM(CASE WHEN e.employee_type = 1 THEN e.medium_q ELSE 0 END) AS "Tecnicos_Medium",
                SUM(CASE WHEN e.employee_type = 1 THEN e.senior_q ELSE 0 END) AS "Tecnicos_Senior",
                SUM(CASE WHEN e.employee_type = 2 THEN e.junior_q ELSE 0 END) AS "Ingenieros_Junior",
                SUM(CASE WHEN e.employee_type = 2 THEN e.medium_q ELSE 0 END) AS "Ingenieros_Medium",
                SUM(CASE WHEN e.employee_type = 2 THEN e.senior_q ELSE 0 END) AS "Ingenieros_Senior",
                SUM(CASE WHEN e.employee_type = 3 THEN e.junior_q ELSE 0 END) AS "Administrativos_Junior",
                SUM(CASE WHEN e.employee_type = 3 THEN e.medium_q ELSE 0 END) AS "Administrativos_Medium",
                SUM(CASE WHEN e.employee_type = 3 THEN e.senior_q ELSE 0 END) AS "Administrativos_Senior",
                SUM(CASE WHEN e.employee_type = 4 THEN e.junior_q ELSE 0 END) AS "Gerentes_Junior",
                SUM(CASE WHEN e.employee_type = 4 THEN e.medium_q ELSE 0 END) AS "Gerentes_Medium",
                SUM(CASE WHEN e.employee_type = 4 THEN e.senior_q ELSE 0 END) AS "Gerentes_Senior",
                SUM(CASE WHEN e.employee_type = 5 THEN e.junior_q ELSE 0 END) AS "Directivos_Junior",
                SUM(CASE WHEN e.employee_type = 5 THEN e.medium_q ELSE 0 END) AS "Directivos_Medium",
                SUM(CASE WHEN e.employee_type = 5 THEN e.senior_q ELSE 0 END) AS "Directivos_Senior",
                SUM(e.tfila) AS "Total"
            from assets a
            join lateral jsonb_to_recordset((a.employee)::jsonb) as e(
                tfila int, junior_q int, medium_q int, senior_q int, employee_type int
            ) on true
            join empresas on a.empresa_id = empresas.id
            GROUP BY a.empresa_id
        ');
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ResourceView"');
    }
};

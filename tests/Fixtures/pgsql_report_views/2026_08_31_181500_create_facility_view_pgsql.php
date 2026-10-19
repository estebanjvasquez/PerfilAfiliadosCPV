<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de FacilityView (Fase 2).
 *
 * *** DRIFT CONFIRMADO (mismo patron que MachineryView - parece un copy-paste entre ambas
 * migraciones originales) ***: el archivo de migracion
 * (database/migrations_mysql_only_views/2023_05_26_204049_create_facility_view.php) tiene
 * `ELSE '> 100'` en el CASE interno de `facility_own` (un valor que ni siquiera tiene sentido para
 * "propiedad de instalacion" - resabio literal copiado de MachineryView). La definicion REAL
 * verificada con SHOW CREATE VIEW FacilityView usa `ELSE NULL`. Se escribe contra la definicion real.
 *
 * `MAX(CASE WHEN e.facility_type = N THEN e.facility_q ELSE ' ' END)`: mezcla una columna
 * (`facility_q`/`facility_surf`, de JSON_TABLE) con el literal string `' '` en las dos ramas del
 * CASE. MySQL coacciona esto implicitamente a texto; Postgres exige un tipo compatible entre ramas
 * y falla si no se castea explicitamente.
 *
 * *** Bug de datos real encontrado al migrar (bloqueante, no cosmetico) ***: al declarar
 * `facility_surf`/`facility_q`/etc. como `int` en `jsonb_to_recordset` (paralelo directo del
 * `INT PATH '...'` de JSON_TABLE), la migracion fallaba con SQLSTATE 22P02 ("invalid input syntax
 * for type integer") en el asset id 25. Diagnostico (sin imprimir datos de terceros, solo
 * longitudes/booleanos - ver scratchpad diag_facility_*.php): ese registro tiene
 * `facility_surf` guardado como el STRING "860.72" (con decimales) en uno de sus elementos del
 * array, algo que Postgres rechaza al castear directo a `int` pero que MySQL admite via su propia
 * coercion JSON->INT de JSON_TABLE. Se confirmo el comportamiento exacto de MySQL comparando contra
 * la fila real de FacilityView para esa empresa: "860.72" -> 861 y, en otro elemento del mismo
 * registro, "97.1" -> 97 - es decir, MySQL REDONDEA al entero mas cercano (no trunca) al convertir
 * el JSON a la columna INT de JSON_TABLE. Se replica declarando las 4 columnas del recordset como
 * `numeric` (acepta cualquier string numerico, con o sin decimales) y aplicando `round(...)::int`
 * antes de convertir a texto o comparar - asi el pipeline completo coincide con el redondeo real de
 * MySQL en vez de fallar o truncar. Se aplica el mismo tratamiento a las 4 columnas por consistencia
 * y robustez, aunque solo `facility_surf` del asset 25 presenta decimales en los datos reales hoy
 * (verificado: 0 casos de decimales en facility_q/facility_own/facility_type en toda la tabla).
 *
 * `JSON_TABLE(a.facility, '$[*]' COLUMNS(...)) e` -> `jsonb_to_recordset` + `JOIN LATERAL ... ON
 * true`, mismo patron que ResourceView/MachineryView (misma exclusion implicita de assets con
 * `facility` NULL o `[]`, mismo cast `::jsonb` de la columna `$table->json(...)`).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "FacilityView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"FacilityView\" AS select
                a.empresa_id as id,
                max(empresas.name) as name,
                MAX(CASE WHEN round(e.facility_type)::int = 0 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Oficinas_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 0 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Oficinas_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 0 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Oficinas_own\",
                MAX(CASE WHEN round(e.facility_type)::int = 1 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Talleres_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 1 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Talleres_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 1 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Talleres_own\",
                MAX(CASE WHEN round(e.facility_type)::int = 2 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Manufactura_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 2 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Manufactura_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 2 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Manufactura_own\",
                MAX(CASE WHEN round(e.facility_type)::int = 3 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Almacenes_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 3 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Almacenes_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 3 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Almacenes_own\",
                MAX(CASE WHEN round(e.facility_type)::int = 4 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Laboratorios_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 4 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Laboratorios_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 4 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Laboratorios_own\",
                MAX(CASE WHEN round(e.facility_type)::int = 5 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Marinas_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 5 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Marinas_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 5 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Marinas_own\",
                MAX(CASE WHEN round(e.facility_type)::int = 6 THEN round(e.facility_q)::int::text ELSE ' ' END) AS \"Otros_q\",
                MAX(CASE WHEN round(e.facility_type)::int = 6 THEN round(e.facility_surf)::int::text ELSE ' ' END) AS \"Otros_surf\",
                MAX(CASE WHEN round(e.facility_type)::int = 6 THEN (CASE WHEN round(e.facility_own)::int = 1 THEN 'Propia' WHEN round(e.facility_own)::int = 2 THEN 'Alquilada' WHEN round(e.facility_own)::int = 3 THEN 'Ambas' ELSE NULL END) ELSE ' ' END) AS \"Otros_own\"
            from assets a
            join lateral jsonb_to_recordset((a.facility)::jsonb) as e(
                facility_q numeric, facility_own numeric, facility_surf numeric, facility_type numeric
            ) on true
            join empresas on a.empresa_id = empresas.id
            GROUP BY a.empresa_id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "FacilityView"');
    }
};

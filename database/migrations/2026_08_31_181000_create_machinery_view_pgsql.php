<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de MachineryView (Fase 2).
 *
 * *** DRIFT CONFIRMADO (igual leccion que catalogoView) ***: el archivo de migracion
 * (database/migrations_mysql_only_views/2023_05_25_223154_create_machinery_view.php) colapsa el
 * CASE interno de machinery_qid/machinery_est a un `ELSE '> 100'` / `ELSE '> 10.000.001 USD'`
 * generico (cubriendo tanto el valor 4 como cualquier otro valor no listado, incluido NULL). La
 * definicion REAL verificada con SHOW CREATE VIEW MachineryView es distinta: tiene una rama
 * explicita `WHEN machinery_qid = 4 THEN '> 100'` (y equivalente para machinery_est = 4) seguida de
 * `ELSE NULL` - es decir, en produccion cualquier fila con machinery_qid/machinery_est fuera de
 * 1-4 (incluido NULL) da NULL, NO '> 100'. Se escribe esta vista contra la definicion REAL, no
 * contra el archivo de migracion.
 *
 * `JSON_TABLE(a.machinery, '$[*]' COLUMNS(...)) e` -> `jsonb_to_recordset` + `JOIN LATERAL ... ON
 * true`, mismo patron que ResourceView (misma exclusion implicita de assets con `machinery` NULL o
 * `[]`, y mismo cast `::jsonb` porque la columna es `$table->json(...)` de Laravel = tipo `json`
 * en Postgres). Los `MAX(CASE WHEN e.machinery_name = N THEN (...) ELSE ' ' END)` son
 * comparaciones enteras normales (machinery_name/qid/est vienen de jsonb_to_recordset como INT),
 * no columnas booleanas - no requieren el ajuste `IS TRUE` que si necesito ManagementDetView.
 *
 * `ORDER BY id` al final de la vista (alias de a.empresa_id) es valido tanto en MySQL como en
 * Postgres (referenciar el alias de SELECT en el ORDER BY de la misma consulta).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "MachineryView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"MachineryView\" AS select
                a.empresa_id as id,
                max(empresas.name) as name,
                MAX(CASE WHEN e.machinery_name = 0 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Equip_med_lev_qua\",
                MAX(CASE WHEN e.machinery_name = 0 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Equip_med_lev_est\",
                MAX(CASE WHEN e.machinery_name = 1 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Equip_mar_flu_qua\",
                MAX(CASE WHEN e.machinery_name = 1 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Equip_mar_flu_est\",
                MAX(CASE WHEN e.machinery_name = 2 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Mov_terr_cons_qua\",
                MAX(CASE WHEN e.machinery_name = 2 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Mov_terr_cons_est\",
                MAX(CASE WHEN e.machinery_name = 3 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Equip_men_cons_qua\",
                MAX(CASE WHEN e.machinery_name = 3 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Equip_men_cons_est\",
                MAX(CASE WHEN e.machinery_name = 4 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Fab_metal_elec_qua\",
                MAX(CASE WHEN e.machinery_name = 4 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Fab_metal_elec_est\",
                MAX(CASE WHEN e.machinery_name = 5 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Mont_elec_meca_qua\",
                MAX(CASE WHEN e.machinery_name = 5 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Mont_elec_meca_est\",
                MAX(CASE WHEN e.machinery_name = 6 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Maq_herr_meca_qua\",
                MAX(CASE WHEN e.machinery_name = 6 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Maq_herr_meca_est\",
                MAX(CASE WHEN e.machinery_name = 7 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Almac_trans_qua\",
                MAX(CASE WHEN e.machinery_name = 7 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Almac_trans_est\",
                MAX(CASE WHEN e.machinery_name = 8 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' WHEN e.machinery_qid = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Serv_poz_inst_qua\",
                MAX(CASE WHEN e.machinery_name = 8 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' WHEN e.machinery_est = 4 THEN '> 10.000.001 USD' ELSE NULL END) ELSE ' ' END) AS \"Serv_poz_inst_est\"
            from assets a
            join lateral jsonb_to_recordset((a.machinery)::jsonb) as e(
                machinery_est int, machinery_qid int, machinery_name int
            ) on true
            join empresas on a.empresa_id = empresas.id
            GROUP BY a.empresa_id
            ORDER BY id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "MachineryView"');
    }
};

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMachineryView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW MachineryView AS
SELECT	
            a.empresa_id as id,
            max(empresas.name) as name,
            MAX(CASE WHEN e.machinery_name = 0 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Equip_med_lev_qua,
            MAX(CASE WHEN e.machinery_name = 0 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Equip_med_lev_est,
            MAX(CASE WHEN e.machinery_name = 1 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Equip_mar_flu_qua,
            MAX(CASE WHEN e.machinery_name = 1 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Equip_mar_flu_est,
            MAX(CASE WHEN e.machinery_name = 2 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Mov_terr_cons_qua,
            MAX(CASE WHEN e.machinery_name = 2 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Mov_terr_cons_est,
            MAX(CASE WHEN e.machinery_name = 3 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Equip_men_cons_qua,
            MAX(CASE WHEN e.machinery_name = 3 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Equip_men_cons_est,
            MAX(CASE WHEN e.machinery_name = 4 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Fab_metal_elec_qua,
            MAX(CASE WHEN e.machinery_name = 4 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Fab_metal_elec_est,
            MAX(CASE WHEN e.machinery_name = 5 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Mont_elec_meca_qua,
            MAX(CASE WHEN e.machinery_name = 5 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Mont_elec_meca_est,
            MAX(CASE WHEN e.machinery_name = 6 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Maq_herr_meca_qua,
            MAX(CASE WHEN e.machinery_name = 6 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Maq_herr_meca_est,
            MAX(CASE WHEN e.machinery_name = 7 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Almac_trans_qua,
            MAX(CASE WHEN e.machinery_name = 7 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Almac_trans_est,
            MAX(CASE WHEN e.machinery_name = 8 THEN (CASE WHEN e.machinery_qid = 1 THEN '1 - 10' WHEN e.machinery_qid = 2 THEN '11 - 50' WHEN e.machinery_qid = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Serv_poz_inst_qua,
            MAX(CASE WHEN e.machinery_name = 8 THEN (CASE WHEN e.machinery_est = 1 THEN '< 100.000 USD' WHEN e.machinery_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.machinery_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.001 USD' END) ELSE ' ' END) AS Serv_poz_inst_est
            
        FROM assets a
        CROSS JOIN JSON_TABLE(
            a.machinery,
            '$[*]' COLUMNS(
                machinery_est INT PATH '$.machinery_est',
                machinery_qid INT PATH '$.machinery_qid',
                machinery_name INT PATH '$.machinery_name'                
            )
        ) e
        join empresas on a.empresa_id = empresas.id

        GROUP BY a.empresa_id

        ORDER BY id
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS MachineryView');
    }
};

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW InventoryView AS
        SELECT	
            a.empresa_id as id,
            max(empresas.name) as name,
            MAX(CASE WHEN e.inventory_name = 0 THEN (CASE WHEN e.inventory_q = 1 THEN '1 - 10' WHEN e.inventory_q = 2 THEN '11 - 50' WHEN e.inventory_q = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Materia_q,
            MAX(CASE WHEN e.inventory_name = 0 THEN (CASE WHEN e.inventory_est = 1 THEN '< 100.000 USD' WHEN e.inventory_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.inventory_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.000 USD' END) ELSE ' ' END) AS Materia_est,
            MAX(CASE WHEN e.inventory_name = 0 THEN e.inventory_unit ELSE ' ' END) AS Materia_unit,
            MAX(CASE WHEN e.inventory_name = 1 THEN (CASE WHEN e.inventory_q = 1 THEN '1 - 10' WHEN e.inventory_q = 2 THEN '11 - 50' WHEN e.inventory_q = 3 THEN '51 - 100' ELSE '> 100' END) ELSE ' ' END) AS Producto_q,
            MAX(CASE WHEN e.inventory_name = 1 THEN (CASE WHEN e.inventory_est = 1 THEN '< 100.000 USD' WHEN e.inventory_est = 2 THEN '100.001 - 1.000.000 USD' WHEN e.inventory_est = 3 THEN '1.000.001 - 10.000.000 USD' ELSE '> 10.000.000 USD' END) ELSE ' ' END) AS Producto_est,
            MAX(CASE WHEN e.inventory_name = 1 THEN e.inventory_unit ELSE ' ' END) AS Producto_unit
            
        FROM assets a
        CROSS JOIN JSON_TABLE(
            a.inventory,
            '$[*]' COLUMNS(
                inventory_q INT PATH '$.inventory_q',
                inventory_est INT PATH '$.inventory_est',
                inventory_unit TEXT PATH '$.inventory_unit',
                inventory_name TINYTEXT PATH '$.inventory_name'
            )
        ) e
        join empresas on a.empresa_id = empresas.id

        GROUP BY a.empresa_id
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS FacilityView');
    }
};

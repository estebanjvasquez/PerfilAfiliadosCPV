<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

//return new class extends Migration
class CreateCapacityView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW capacityView AS
            select 
            empresas.id as id, empresas.name, sectors.name as Sector, services.name as Servicios, 
    
    max((SELECT sum(resultj) +  sum(resultm) + sum(results)
    FROM assets,
    JSON_TABLE(
        `employee`,
        '$[*]' COLUMNS(           
            NESTED PATH '$.junior_q' COLUMNS (resultj DECIMAL PATH '$'),
            NESTED PATH '$.medium_q' COLUMNS (resultm DECIMAL PATH '$'),
            NESTED PATH '$.senior_q' COLUMNS (results DECIMAL PATH '$')
        )
    ) AS TOT_RH  where assets.empresa_id = empresas.id))  as rrhh, 
    
    
    max((SELECT sum(resultf)
    FROM assets,
    JSON_TABLE(
        `facility`,
        '$[*]' COLUMNS(           
            NESTED PATH '$.facility_q' COLUMNS (resultf DECIMAL PATH '$')         
        )
    ) AS TOT_INST where assets.empresa_id = empresas.id))  as instalaciones,

    max((SELECT CASE MAX(resultmach) 
            WHEN '1' THEN '1-10'
            WHEN '2' THEN '11-50'
            WHEN '3' THEN '51-100'
            WHEN '4' THEN '> 100'
            END AS MAQ 
    FROM assets,
        JSON_TABLE(`machinery`,'$[*]' COLUMNS(NESTED PATH '$.machinery_qid' COLUMNS (resultmach DECIMAL PATH '$'))) AS TOT_MAQ where assets.empresa_id = empresas.id)) as maquinaria,   
 
    max((SELECT CASE MAX(resultinv) 
            WHEN '1' THEN '< 100000'
            WHEN '2' THEN '100001 - 1000000'
            WHEN '3' THEN '1000001 - 10000000'
            WHEN '4' THEN '> 10000000'
            END AS MAQ 
    FROM assets,
        JSON_TABLE(`inventory`,'$[*]' COLUMNS(NESTED PATH '$.facility_own' COLUMNS (resultinv DECIMAL PATH '$'))) AS TOT_INV where assets.empresa_id = empresas.id)) as inventario  
 
    from 
	    empresas
    
    left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
    left join services on empresa_sector_service.service_id = services.id
    left join sectors on services.sectors_id = sectors.id
    
    GROUP BY 1, 2, 3, 4
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW capacityView');
    }
};

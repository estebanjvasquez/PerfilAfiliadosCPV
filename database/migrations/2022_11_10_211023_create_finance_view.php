<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFinanceView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW FinanceView AS
            select 
                empresas.id as id, empresas.name, GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as Sector, 

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

                CASE 
                    WHEN billing_id = '1' THEN '< 100.000 USD'
                    WHEN billing_id = '2' THEN '100.001 - 1.000.000 USD'
                    WHEN billing_id = '3' THEN '1.000.001 - 10.000.000 USD'
                    ELSE '> 10.000.001 USD'
                END AS BILLING,
                
                CASE 
                    WHEN status_id = '1' THEN 'Activa'
                    WHEN status_id = '0' THEN 'Inactiva'
                    ELSE NULL
                END AS ESTADO,
                
                CASE 
                    WHEN property_id = '1' THEN 'Privado'
                    WHEN property_id = '0' THEN 'Publico'
                    ELSE NULL
                END AS CAPITAL,
                
                CASE 
                    WHEN origin_id = '1' THEN 'Nacional'
                    WHEN origin_id = '0' THEN 'Internacional'
                    ELSE NULL
                END AS ORIGEN
            
            from 
                empresas
                
                left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                left join services on empresa_sector_service.service_id = services.id
                left join sectors on services.sectors_id = sectors.id
            
            GROUP BY 1, 2
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS FinanceView');
    }
};

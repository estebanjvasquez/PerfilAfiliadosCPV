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
                empresas.id as id, empresas.name, sectors.name as Sector, 
                CASE 
                    WHEN billing_id = '1' THEN '< 100000 USD'
                    WHEN billing_id = '2' THEN '100001 - 1000000 USD'
                    WHEN billing_id = '3' THEN '1000001 - 10000000 USD'
                    ELSE '> 10000001 USD'
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
            
            GROUP BY 1, 2, 3
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

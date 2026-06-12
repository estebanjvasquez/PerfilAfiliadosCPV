<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateClientsView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW ClientsView AS
            SELECT 
                id, 
                name,
                Sector,
                REPLACE(rec->>'$.customer_name', 'null', '') cliente,
                (select countries.country_name from countries where countries.id = rec->>'$.country_id') pais
                                                
            FROM (
                SELECT t.id, t.name, 
                (SELECT GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as sectores from empresas 
                left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                left join services on empresa_sector_service.service_id = services.id
                left join sectors on services.sectors_id = sectors.id
                where empresas.id = t.id) as Sector,    
                JSON_EXTRACT(t.customers_country, CONCAT('$[', x.idx, ']')) AS rec
                FROM 
                    empresas t
                    INNER JOIN ( 
                        SELECT 0 AS idx UNION ALL SELECT 1 UNION ALL SELECT 2 
                    ) 
                
                AS x ON JSON_EXTRACT(t.customers_country, CONCAT('$[', x.idx, ']')) IS NOT NULL

                ) z

            ORDER BY 1
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS ClientsView');
    }
};

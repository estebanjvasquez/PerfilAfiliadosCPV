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
        DB::statement("DROP VIEW IF EXISTS ClientsView");
        DB::statement("CREATE VIEW ClientsView AS
            SELECT
                id,
                name,
                Sector,
                REPLACE(json_unquote(json_extract(rec, '\$.customer_name')), 'null', '') cliente,
                (SELECT countries.country_name FROM countries WHERE countries.id = json_unquote(json_extract(rec, '\$.country_id'))) pais
            FROM (
                SELECT 
                    t.id, 
                    t.name, 
                    (
                        SELECT GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as sectores 
                        FROM empresas 
                        LEFT JOIN empresa_sector_service ON empresas.id = empresa_sector_service.empresa_id
                        LEFT JOIN services ON empresa_sector_service.service_id = services.id
                        LEFT JOIN sectors ON services.sectors_id = sectors.id
                        WHERE empresas.id = t.id
                    ) as Sector,    
                    JSON_EXTRACT(t.customers_country, CONCAT('$[', x.idx, ']')) AS rec
                FROM 
                    empresas t
                    INNER JOIN (
                        SELECT ROW_NUMBER() OVER () - 1 AS idx 
                        FROM (
                            SELECT DISTINCT t.id
                            FROM empresas t
                            CROSS JOIN json_table(t.customers_country, '$[*]' COLUMNS (id INT PATH '$')) AS jc
                        ) AS subquery
                    ) AS x ON JSON_UNQUOTE(JSON_EXTRACT(t.customers_country, CONCAT('$[', x.idx, ']'))) IS NOT NULL
            ) z
            ORDER BY 1;
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

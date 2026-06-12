<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateExperienceView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
      CREATE VIEW ExperienceView AS
      SELECT 
                empresa_id as id, 
                (select empresas.name from empresas where id = empresa_id) as name,
                (select infrasectors.sector_name from infrasectors where id = rec->>'$.data.infrasectors_id') as sectorind,
                (select infratypes.type_name from infratypes where id = rec->>'$.data.infratypes_id') as tipoind,
                (select infrasystems.system_name from infrasystems where id = rec->>'$.data.infrasystems_id') as systemind,
                (select infraregions.region_name from infraregions where id = rec->>'$.data.infraregions_id') as regionind,
                (select infrafacilities.facility_name from infrafacilities where id = rec->>'$.data.infrafacilities_id') as facilityind,
                
                (select sectors.name from sectors where id = rec->>'$.data.sectors_id') as sector,
                
                (select GROUP_CONCAT(services.name, ' ') from services where id in                
                (SELECT JSON_EXTRACT(rec->>'$.data.services_id', CONCAT('$[', idx, ']'))
				FROM ( SELECT 0 idx UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) src)) as service,
                
                rec->>'$.data.exp_year' ano,                 
                                
                CASE rec->>'$.data.magnitud'
                    WHEN '1' THEN '< 100.000 USD'
                    WHEN '2' THEN '100.001 - 1.000.000 USD'
                    WHEN '3' THEN '1.000.001 - 10.000.000 USD'
                    ELSE '> 10.000.001 USD'
                END AS magnitud,
                
                REPLACE(rec->>'$.data.prof_tech', 'null', '') prof_tech,
                REPLACE(rec->>'$.data.manpower', 'null', '') manpower,
                                 
                rec->>'$.data.Descripcion' descripcion
            FROM (
                SELECT t.empresa_id, 
                
                JSON_EXTRACT(t.exp_year, CONCAT('$[', x.idx, ']')) AS rec
                FROM 
                    experiences t
                    INNER JOIN ( 
                        SELECT ROW_NUMBER() OVER () - 1 AS idx 
                        FROM (
                            SELECT DISTINCT t.id
                            FROM empresas t
                            CROSS JOIN json_table(t.customers_country, '$[*]' COLUMNS (id INT PATH '$')) AS jc
                        ) AS subquery
                    )                     
                AS x ON JSON_EXTRACT(t.exp_year, CONCAT('$[', x.idx, ']')) IS NOT NULL

                left join experiences on experiences.empresa_id = t.id            
                ) z

            ORDER BY empresa_id
            ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS ExperienceView');
    }
};

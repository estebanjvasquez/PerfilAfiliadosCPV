<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DROP VIEW IF EXISTS ExperienceView");
        DB::statement("CREATE VIEW ExperienceView AS
            SELECT
                empresa_id as id,
                (select empresas.name from empresas where id = empresa_id) as name,
                sectores,
                servicios,
                (select infrasectors.sector_name from infrasectors where id = json_unquote(json_extract(rec, '\$.data.infrasectors_id'))) as sectorind,
                (select infratypes.type_name from infratypes where id = json_unquote(json_extract(rec, '\$.data.infratypes_id'))) as tipoind,
                (select infrasystems.system_name from infrasystems where id = json_unquote(json_extract(rec, '\$.data.infrasystems_id'))) as systemind,
                (select infraregions.region_name from infraregions where id = json_unquote(json_extract(rec, '\$.data.infraregions_id'))) as regionind,
                (select infrafacilities.facility_name from infrafacilities where id = json_unquote(json_extract(rec, '\$.data.infrafacilities_id'))) as facilityind,
                json_unquote(json_extract(rec, '\$.data.exp_year')) ano

            FROM (
                SELECT t.empresa_id, 
                (SELECT GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as sectores from empresas 
                            left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                            left join services on empresa_sector_service.service_id = services.id
                            left join sectors on services.sectors_id = sectors.id
                            where empresas.id = t.empresa_id) as sectores, 
                
                (SELECT GROUP_CONCAT(DISTINCT services.name SEPARATOR ', ') as servicios from empresas 
                            left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                            left join services on empresa_sector_service.service_id = services.id
                            left join sectors on services.sectors_id = sectors.id
                            where empresas.id = t.empresa_id) as servicios, 
                
                JSON_EXTRACT(t.exp_year, CONCAT('$[', x.idx, ']')) AS rec
                FROM 
                    experiences t
                    INNER JOIN ( 
                        SELECT 0 AS idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
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

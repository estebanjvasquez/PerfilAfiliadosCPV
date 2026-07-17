<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFacilityView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DROP VIEW IF EXISTS FacilityView");
        DB::statement("CREATE VIEW FacilityView AS
        SELECT	
            a.empresa_id as id,
            max(empresas.name) as name,
            MAX(CASE WHEN e.facility_type = 0 THEN e.facility_q ELSE ' ' END) AS Oficinas_q,
            MAX(CASE WHEN e.facility_type = 0 THEN e.facility_surf ELSE ' ' END) AS Oficinas_surf,
            MAX(CASE WHEN e.facility_type = 0 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Oficinas_own,
            MAX(CASE WHEN e.facility_type = 1 THEN e.facility_q ELSE ' ' END) AS Talleres_q,
            MAX(CASE WHEN e.facility_type = 1 THEN e.facility_surf ELSE ' ' END) AS Talleres_surf,
            MAX(CASE WHEN e.facility_type = 1 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Talleres_own,
            MAX(CASE WHEN e.facility_type = 2 THEN e.facility_q ELSE ' ' END) AS Manufactura_q,
            MAX(CASE WHEN e.facility_type = 2 THEN e.facility_surf ELSE ' ' END) Manufactura_surf,
            MAX(CASE WHEN e.facility_type = 2 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Manufactura_own,
            MAX(CASE WHEN e.facility_type = 3 THEN e.facility_q ELSE ' ' END) AS Almacenes_q,
            MAX(CASE WHEN e.facility_type = 3 THEN e.facility_surf ELSE ' ' END) AS Almacenes_surf,
            MAX(CASE WHEN e.facility_type = 3 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Almacenes_own,
            MAX(CASE WHEN e.facility_type = 4 THEN e.facility_q ELSE ' ' END) AS Laboratorios_q,
            MAX(CASE WHEN e.facility_type = 4 THEN e.facility_surf ELSE ' ' END) AS Laboratorios_surf,
            MAX(CASE WHEN e.facility_type = 4 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Laboratorios_own,
            MAX(CASE WHEN e.facility_type = 5 THEN e.facility_q ELSE ' ' END) AS Marinas_q,
            MAX(CASE WHEN e.facility_type = 5 THEN e.facility_surf ELSE ' ' END) AS Marinas_surf,
            MAX(CASE WHEN e.facility_type = 5 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Marinas_own,
            MAX(CASE WHEN e.facility_type = 6 THEN e.facility_q ELSE ' ' END) AS Otros_q,
            MAX(CASE WHEN e.facility_type = 6 THEN e.facility_surf ELSE ' ' END) AS Otros_surf,
            MAX(CASE WHEN e.facility_type = 6 THEN (CASE WHEN e.facility_own = 1 THEN 'Propia' WHEN e.facility_own = 2 THEN 'Alquilada' WHEN e.facility_own = 3 THEN 'Ambas' ELSE '> 100' END) ELSE ' ' END) AS Otros_own
        FROM assets a
        CROSS JOIN JSON_TABLE(
            a.facility,
            '$[*]' COLUMNS(
                facility_q INT PATH '$.facility_q',
                facility_own INT PATH '$.facility_own',
                facility_surf INT PATH '$.facility_surf',
                facility_type INT PATH '$.facility_type'
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

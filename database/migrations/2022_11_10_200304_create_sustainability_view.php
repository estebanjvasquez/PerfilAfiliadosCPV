<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSustainabilityView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW SustainabilityView AS
            select 
                empresas.id as id, empresas.name, GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as Sector, 
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 1 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Maximizacion,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 2 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Creacion,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 3 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Energias,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 4 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Funcionalidad,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 5 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Participacion,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 6 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Fomento,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 7 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Reorientacion,
                IF ((select count(sustainabilities.areas_id) from sustainabilities where areas_id = 8 and sustainabilities.empresa_id = empresas.id), 'Sí', 'No') as Desarrollo
            from 
                empresas
            
                left join sustainabilities on empresas.id = sustainabilities.empresa_id
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
        DB::statement('DROP VIEW IF EXISTS SustainabilityView');
    }
};

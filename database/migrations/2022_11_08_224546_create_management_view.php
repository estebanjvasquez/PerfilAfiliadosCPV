<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateManagementView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW ManagementView AS
            select 
                empresas.id as id, empresas.name, GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as Sector, 
                IF(management.iso9001 = 1 OR management.iso17025 = 1 OR management.quality_otros = 1, 'Si', 'No') AS Calidad,
                IF(management.iso14001 = 1 OR management.iso50001 = 1 OR management.environment_otros = 1, 'Si', 'No') AS Ambiente,
                IF(management.iso45001 = 1 OR management.ovid = 1 OR management.security_otros = 1, 'Si', 'No') AS Seguridad,
                IF(management.pmi = 1 OR management.pmi_otros = 1, 'Si', 'No') AS Gestion,
                IF(management.dun = 1 OR management.iso37001 = 1 OR management.credibility_otros = 1, 'Si', 'No') AS Credibilidad,
                IF(management.iso27001 = 1 OR management.info_otros = 1, 'Si', 'No') AS Informacion
            from 
                empresas
            
                left join management on empresas.id = management.empresa_id
                left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                left join services on empresa_sector_service.service_id = services.id
                left join sectors on services.sectors_id = sectors.id
            
            GROUP BY 1, 2, 4, 5, 6, 7, 8, 9
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('DROP VIEW IF EXISTS ManagementView');
    }
};

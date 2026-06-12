<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

//return new class extends Migration
class CreateCatalogoView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW catalogoView AS select 
        empresas.id as id, 
        empresas.name, 
        GROUP_CONCAT(DISTINCT sectors.name SEPARATOR ', ') as Sector, 
        empresas.street, 
        CONCAT(cities.city_name, ', ', countries.country_name) as CIUDAD, 
        empresas.ano_fund as 'fundacion', 
        empresas.rif as rif,
        empresas.phone as phone,
        empresas.website as website,

	    GROUP_CONCAT(DISTINCT contacts.name, ' (', CONCAT_WS(', ', contacts.position, contacts.phone, contacts.email), ') ' ORDER BY contacts.name SEPARATOR '; \n') as CONTACTOS, 
        
       GROUP_CONCAT(DISTINCT chambers.name SEPARATOR ', ') as camara        
        
        from 
            empresas
            
        inner join empresa_user on empresas.id = empresa_user.empresa_id
        left join cities on empresas.city_id = cities.id
        left join countries on cities.country_id = countries.id
        
        left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
        left join services on empresa_sector_service.service_id = services.id
        left join sectors on services.sectors_id = sectors.id
        
        left join contact_empresa on empresas.id = contact_empresa.empresa_id 
        left join contacts on contact_empresa.contact_id = contacts.id
        
        left join chamber_empresa on empresas.id = chamber_empresa.empresa_id
        left join chambers on chamber_empresa.chamber_id = chambers.id

        GROUP BY 1, 2, 4, 5, 6, 7, 8, 9");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("DROP VIEW catalogoView");
    }
}

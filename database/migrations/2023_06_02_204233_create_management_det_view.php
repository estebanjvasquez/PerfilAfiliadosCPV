<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateManagementDetView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("CREATE VIEW ManagementDetView AS
        SELECT	
            a.empresa_id as id,
            max(empresas.name) as name,
            max(CASE WHEN iso9001 = 0 THEN 'NO' ELSE 'SÍ' END) as iso9001,
            max(CASE WHEN iso17025 = 0 THEN 'NO' ELSE 'SÍ' END) as iso17025,
            MAX(CASE WHEN quality_otros = 0 THEN 'NO' ELSE TRIM('\"]' FROM (TRIM('[\"' FROM (quality_data ->> '$[*].quality_otros_name')))) END) AS QUALITY_OTROS,
            max(CASE WHEN iso14001 = 0 THEN 'NO' ELSE 'SÍ' END) as iso14001,
            max(CASE WHEN iso50001 = 0 THEN 'NO' ELSE 'SÍ' END) as iso50001,
            MAX(CASE WHEN environment_otros = 0 THEN 'NO' ELSE TRIM('\"]' FROM (TRIM('[\"' FROM (environment_data ->> '$[*].environment_otros_name')))) END) AS ENVIRONMENT_OTROS,
            max(CASE WHEN dun = 0 THEN 'NO' ELSE 'SÍ' END) as dun,
            max(CASE WHEN iso37001 = 0 THEN 'NO' ELSE 'SÍ' END) as iso37001,
            MAX(CASE WHEN credibility_otros = 0 THEN 'NO' ELSE TRIM('\"]' FROM (TRIM('[\"' FROM (credibility_data ->> '$[*].credibility_otros_name')))) END) AS CREDIBILITY_OTROS,
            MAX(CASE WHEN iso45001 = 0 THEN 'NO' ELSE 'SÍ' END) as iso45001,
            MAX(CASE WHEN ovid = 0 THEN 'NO' ELSE 'SÍ' END) as ovid,
            MAX(CASE WHEN security_otros = 0 THEN 'NO' ELSE TRIM('\"]' FROM (TRIM('[\"' FROM (security_data ->> '$[*].security_otros_name')))) END) AS SECURITY_OTROS,
            MAX(CASE WHEN pmi = 0 THEN 'NO' ELSE 'SÍ' END) as pmi,
            MAX(CASE WHEN pmi_otros = 0 THEN 'NO' ELSE TRIM('\"]' FROM (TRIM('[\"' FROM (pmi_data ->> '$[*].pim_otros_name')))) END) AS PMI_OTROS,
            MAX(CASE WHEN iso27001 = 0 THEN 'NO' ELSE 'SÍ' END) as iso27001,
            MAX(CASE WHEN info_otros = 0 THEN 'NO' ELSE TRIM('\"]' FROM (TRIM('[\"' FROM (info_data ->> '$[*].info_otros_name')))) END) AS INFO_OTROS
            
            FROM management a        
            
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
        DB::statement('DROP VIEW IF EXISTS ManagementDetView');
    }
};

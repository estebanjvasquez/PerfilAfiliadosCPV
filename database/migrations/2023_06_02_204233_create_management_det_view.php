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
        DB::statement("DROP VIEW IF EXISTS ManagementDetView");
        DB::statement("CREATE VIEW ManagementDetView AS
        SELECT
            a.empresa_id as id,
            empresas.name as name,
            CASE WHEN a.iso9001 = 1 THEN 'SÍ' ELSE 'NO' END AS iso9001,
            CASE WHEN a.iso17025 = 1 THEN 'SÍ' ELSE 'NO' END AS iso17025,
            CASE WHEN a.quality_otros = 1 THEN json_unquote(json_extract(a.quality_data, '\$[*].quality_otros_name')) ELSE 'NO' END AS QUALITY_OTROS,
            CASE WHEN a.iso14001 = 1 THEN 'SÍ' ELSE 'NO' END AS iso14001,
            CASE WHEN a.iso50001 = 1 THEN 'SÍ' ELSE 'NO' END AS iso50001,
            CASE WHEN a.environment_otros = 1 THEN json_unquote(json_extract(a.environment_data, '\$[*].environment_otros_name')) ELSE 'NO' END AS ENVIRONMENT_OTROS,
            CASE WHEN a.dun = 1 THEN 'SÍ' ELSE 'NO' END AS dun,
            CASE WHEN a.iso37001 = 1 THEN 'SÍ' ELSE 'NO' END AS iso37001,
            CASE WHEN a.credibility_otros = 1 THEN json_unquote(json_extract(a.credibility_data, '\$[*].credibility_otros_name')) ELSE 'NO' END AS CREDIBILITY_OTROS,
            CASE WHEN a.iso45001 = 1 THEN 'SÍ' ELSE 'NO' END AS iso45001,
            CASE WHEN a.ovid = 1 THEN 'SÍ' ELSE 'NO' END AS ovid,
            CASE WHEN a.security_otros = 1 THEN json_unquote(json_extract(a.security_data, '\$[*].security_otros_name')) ELSE 'NO' END AS SECURITY_OTROS,
            CASE WHEN a.pmi = 1 THEN 'SÍ' ELSE 'NO' END AS pmi,
            CASE WHEN a.pmi_otros = 1 THEN json_unquote(json_extract(a.pmi_data, '\$[*].pmi_otros_name')) ELSE 'NO' END AS PMI_OTROS,
            CASE WHEN a.iso27001 = 1 THEN 'SÍ' ELSE 'NO' END AS iso27001,
            CASE WHEN a.info_otros = 1 THEN json_unquote(json_extract(a.info_data, '\$[*].info_otros_name')) ELSE 'NO' END AS INFO_OTROS

            FROM management a

            join empresas on a.empresa_id = empresas.id
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

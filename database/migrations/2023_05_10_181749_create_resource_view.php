<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateResourceView extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("DROP VIEW IF EXISTS ResourceView");
        DB::statement("CREATE VIEW ResourceView AS
        SELECT	
            a.empresa_id as id,
            max(empresas.name) as name,
            SUM(CASE WHEN e.employee_type = 0 THEN e.junior_q ELSE 0 END) AS Bachilleres_Junior,
            SUM(CASE WHEN e.employee_type = 0 THEN e.medium_q ELSE 0 END) AS Bachilleres_Medium,
            SUM(CASE WHEN e.employee_type = 0 THEN e.senior_q ELSE 0 END) AS Bachilleres_Senior,
            SUM(CASE WHEN e.employee_type = 1 THEN e.junior_q ELSE 0 END) AS Tecnicos_Junior,
            SUM(CASE WHEN e.employee_type = 1 THEN e.medium_q ELSE 0 END) AS Tecnicos_Medium,
            SUM(CASE WHEN e.employee_type = 1 THEN e.senior_q ELSE 0 END) AS Tecnicos_Senior,
            SUM(CASE WHEN e.employee_type = 2 THEN e.junior_q ELSE 0 END) AS Ingenieros_Junior,
            SUM(CASE WHEN e.employee_type = 2 THEN e.medium_q ELSE 0 END) AS Ingenieros_Medium,
            SUM(CASE WHEN e.employee_type = 2 THEN e.senior_q ELSE 0 END) AS Ingenieros_Senior,
            SUM(CASE WHEN e.employee_type = 3 THEN e.junior_q ELSE 0 END) AS Administrativos_Junior,
            SUM(CASE WHEN e.employee_type = 3 THEN e.medium_q ELSE 0 END) AS Administrativos_Medium,
            SUM(CASE WHEN e.employee_type = 3 THEN e.senior_q ELSE 0 END) AS Administrativos_Senior,
            SUM(CASE WHEN e.employee_type = 4 THEN e.junior_q ELSE 0 END) AS Gerentes_Junior,
            SUM(CASE WHEN e.employee_type = 4 THEN e.medium_q ELSE 0 END) AS Gerentes_Medium,
            SUM(CASE WHEN e.employee_type = 4 THEN e.senior_q ELSE 0 END) AS Gerentes_Senior,
            SUM(CASE WHEN e.employee_type = 5 THEN e.junior_q ELSE 0 END) AS Directivos_Junior,
            SUM(CASE WHEN e.employee_type = 5 THEN e.medium_q ELSE 0 END) AS Directivos_Medium,
            SUM(CASE WHEN e.employee_type = 5 THEN e.senior_q ELSE 0 END) AS Directivos_Senior,
            SUM(e.tfila) AS Total
        FROM assets a
        CROSS JOIN JSON_TABLE(
            a.employee,
            '$[*]' COLUMNS(
                tfila INT PATH '$.tfila',
                junior_q INT PATH '$.junior_q',
                medium_q INT PATH '$.medium_q',
                senior_q INT PATH '$.senior_q',
                employee_type INT PATH '$.employee_type'
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
        DB::statement('DROP VIEW IF EXISTS ResourceView');
    }
};

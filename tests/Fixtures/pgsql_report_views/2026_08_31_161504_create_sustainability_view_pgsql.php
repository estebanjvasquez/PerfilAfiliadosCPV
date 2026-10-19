<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de SustainabilityView (Fase 2 del plan de migracion). Original en
 * database/migrations_mysql_only_views/2022_11_10_200304_create_sustainability_view.php.
 *
 * Traduccion: IF(cond, a, b) -> CASE WHEN cond THEN a ELSE b END (agregando "> 0" explicito,
 * Postgres no trata un entero como booleano en un CASE WHEN); GROUP_CONCAT(DISTINCT x SEPARATOR s)
 * -> string_agg(DISTINCT x, s).
 *
 * $connection = 'pgsql' fuerza que esta migracion corra siempre contra Postgres, sin importar el
 * flag --database usado en `artisan migrate` (para que un `migrate` normal contra mysql no
 * intente crear esta vista ahi con sintaxis incompatible).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "SustainabilityView"');
        DB::connection('pgsql')->statement('
            CREATE VIEW "SustainabilityView" AS
            select
                empresas.id as id, empresas.name, string_agg(DISTINCT sectors.name, \', \') as "Sector",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 1 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Maximizacion",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 2 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Creacion",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 3 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Energias",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 4 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Funcionalidad",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 5 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Participacion",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 6 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Fomento",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 7 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Reorientacion",
                CASE WHEN (select count(sustainabilities.areas_id) from sustainabilities where areas_id = 8 and sustainabilities.empresa_id = empresas.id) > 0 THEN \'Sí\' ELSE \'No\' END as "Desarrollo"
            from
                empresas

                left join sustainabilities on empresas.id = sustainabilities.empresa_id
                left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                left join services on empresa_sector_service.service_id = services.id
                left join sectors on services.sectors_id = sectors.id

            GROUP BY 1, 2
        ');
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "SustainabilityView"');
    }
};

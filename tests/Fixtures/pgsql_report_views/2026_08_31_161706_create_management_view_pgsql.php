<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de ManagementView (Fase 2). Original en
 * database/migrations_mysql_only_views/2022_11_08_224546_create_management_view.php.
 *
 * Traduccion: IF(cond, a, b) -> CASE WHEN cond THEN a ELSE b END; GROUP_CONCAT -> string_agg;
 * "columna_boolean = 1" -> "columna_boolean IS TRUE" (en MySQL estas columnas son tinyint(1) y
 * comparan bien contra 1, pero en Postgres management.* son boolean real - comparar un boolean
 * contra el entero 1 es un error de tipo. IS TRUE ademas es NULL-safe, igual que el resultado de
 * "NULL = 1" en MySQL, que tambien cae en la rama ELSE 'No').
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ManagementView"');
        DB::connection('pgsql')->statement('
            CREATE VIEW "ManagementView" AS
            select
                empresas.id as id, empresas.name, string_agg(DISTINCT sectors.name, \', \') as "Sector",
                CASE WHEN management.iso9001 IS TRUE OR management.iso17025 IS TRUE OR management.quality_otros IS TRUE THEN \'Si\' ELSE \'No\' END AS "Calidad",
                CASE WHEN management.iso14001 IS TRUE OR management.iso50001 IS TRUE OR management.environment_otros IS TRUE THEN \'Si\' ELSE \'No\' END AS "Ambiente",
                CASE WHEN management.iso45001 IS TRUE OR management.ovid IS TRUE OR management.security_otros IS TRUE THEN \'Si\' ELSE \'No\' END AS "Seguridad",
                CASE WHEN management.pmi IS TRUE OR management.pmi_otros IS TRUE THEN \'Si\' ELSE \'No\' END AS "Gestion",
                CASE WHEN management.dun IS TRUE OR management.iso37001 IS TRUE OR management.credibility_otros IS TRUE THEN \'Si\' ELSE \'No\' END AS "Credibilidad",
                CASE WHEN management.iso27001 IS TRUE OR management.info_otros IS TRUE THEN \'Si\' ELSE \'No\' END AS "Informacion"
            from
                empresas

                left join management on empresas.id = management.empresa_id
                left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
                left join services on empresa_sector_service.service_id = services.id
                left join sectors on services.sectors_id = sectors.id

            GROUP BY 1, 2, 4, 5, 6, 7, 8, 9
        ');
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "ManagementView"');
    }
};

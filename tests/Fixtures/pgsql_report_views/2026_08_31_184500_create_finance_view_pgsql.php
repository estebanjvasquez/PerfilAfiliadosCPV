<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de FinanceView (Fase 2) - la ultima de las 13 vistas.
 *
 * *** DRIFT CONFIRMADO (mismo patron de ELSE generico visto en Machinery/Facility/Inventory/
 * ExperienceView) ***: el archivo de migracion
 * (database/migrations_mysql_only_views/2022_11_10_211023_create_finance_view.php) tiene
 * `ELSE '> 10.000.001 USD'` generico en el CASE de `BILLING` (cubriendo tanto billing_id=4 como
 * cualquier otro valor/NULL). La definicion REAL (SHOW CREATE VIEW FinanceView) tiene una rama
 * explicita `WHEN billing_id = '4' THEN '> 10.000.001 USD'` seguida de `ELSE NULL`. Se usa la real.
 *
 * `rrhh` es exactamente la misma subconsulta NESTED PATH que en capacityView (ver ese archivo para
 * el analisis completo de por que el auto-wrap de escalares hace que NESTED PATH sea equivalente a
 * una extraccion directa) - se reutiliza el mismo patron `jsonb_array_elements` + `->>` + numeric.
 *
 * `billing_id`/`status_id`/`property_id`/`origin_id` son `$table->unsignedBigInteger(...)` de
 * Laravel = `bigint` en Postgres, comparados en el original contra literales STRING ('1', '2', ...)
 * - MySQL coacciona bigint a texto implicitamente. Postgres no compara bigint contra texto sin
 * cast explicito; se usan literales numericos (`= 1`, `= 2`, ...) en vez de string, equivalente
 * dado que estas columnas son siempre enteras.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "FinanceView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"FinanceView\" AS select
                empresas.id as id,
                empresas.name,
                string_agg(DISTINCT sectors.name, ', ' ORDER BY sectors.name) as \"Sector\",
                (
                    SELECT SUM(round(coalesce((elem ->> 'junior_q')::numeric, 0)))
                         + SUM(round(coalesce((elem ->> 'medium_q')::numeric, 0)))
                         + SUM(round(coalesce((elem ->> 'senior_q')::numeric, 0)))
                    FROM assets a2
                    CROSS JOIN LATERAL jsonb_array_elements((a2.employee)::jsonb) AS elem
                    WHERE a2.empresa_id = empresas.id
                ) AS rrhh,
                MAX(CASE
                    WHEN empresas.billing_id = 1 THEN '< 100.000 USD'
                    WHEN empresas.billing_id = 2 THEN '100.001 - 1.000.000 USD'
                    WHEN empresas.billing_id = 3 THEN '1.000.001 - 10.000.000 USD'
                    WHEN empresas.billing_id = 4 THEN '> 10.000.001 USD'
                    ELSE NULL
                END) AS \"BILLING\",
                MAX(CASE
                    WHEN empresas.status_id = 1 THEN 'Activa'
                    WHEN empresas.status_id = 0 THEN 'Inactiva'
                    ELSE NULL
                END) AS \"ESTADO\",
                MAX(CASE
                    WHEN empresas.property_id = 1 THEN 'Privado'
                    WHEN empresas.property_id = 0 THEN 'Publico'
                    ELSE NULL
                END) AS \"CAPITAL\",
                MAX(CASE
                    WHEN empresas.origin_id = 1 THEN 'Nacional'
                    WHEN empresas.origin_id = 0 THEN 'Internacional'
                    ELSE NULL
                END) AS \"ORIGEN\"
            from empresas
            left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
            left join services on empresa_sector_service.service_id = services.id
            left join sectors on services.sectors_id = sectors.id
            GROUP BY empresas.id, empresas.name
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "FinanceView"');
    }
};

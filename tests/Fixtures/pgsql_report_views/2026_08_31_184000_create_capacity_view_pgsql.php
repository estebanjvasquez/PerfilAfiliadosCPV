<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de capacityView (Fase 2) - la mas compleja segun el plan original
 * (usa NESTED PATH de JSON_TABLE, sin equivalente directo en Postgres).
 *
 * Verificado con SHOW CREATE VIEW capacityView contra MySQL de produccion: coincide con
 * database/migrations_mysql_only_views/2022_11_06_185050_create_capacity_view.php (bugs incluidos,
 * ver abajo), sin drift adicional. Se corrige de paso el bug ya senalado en el plan: el `down()`
 * original hace `DROP VIEW capacityView` sin `IF EXISTS`.
 *
 * `NESTED PATH '$.campo' COLUMNS(resultX DECIMAL PATH '$')` dentro de un JSON_TABLE con
 * `'$[*]' COLUMNS(...)`: como `campo` (junior_q/medium_q/senior_q/facility_q/machinery_qid/
 * facility_own) es siempre un ESCALAR (no un array) dentro de cada elemento del array exterior,
 * MySQL auto-envuelve ese escalar en un array de 1 elemento antes de aplicar el NESTED PATH (misma
 * regla de auto-wrap ya documentada en ExperienceView) - el resultado neto es EXACTAMENTE
 * equivalente a extraer ese campo directo con `PATH '$.campo'` en el COLUMNS de primer nivel (como
 * en Resource/Machinery/Facility/InventoryView), sin ningun cambio de cardinalidad. El NESTED PATH
 * aqui no aporta nada semantico distinto - simplemente no hacia falta, pero se verifico (contando
 * claves faltantes en los datos reales: 0 en todos los campos usados) que de todos modos el
 * resultado es identico a extraerlo directo. Se traduce con `jsonb_array_elements` + `->>'campo'`
 * + cast a `numeric` (igual patron que FacilityView, por seguridad ante decimales aunque no se
 * encontraron en estos campos especificos).
 *
 * `rrhh` = SUM(junior_q)+SUM(medium_q)+SUM(senior_q) sobre TODOS los elementos del array
 * `employee` de TODAS las filas de `assets` de la empresa (equivalente a sumar las columnas
 * Bachilleres_Junior..Directivos_Senior de ResourceView, pero sin desglosar por employee_type).
 *
 * `instalaciones` = SUM(facility_q) sobre todos los elementos de `facility` (sin desglosar por
 * facility_type, a diferencia de FacilityView).
 *
 * `maquinaria` = mapea el MAX(machinery_qid) observado (1-4) a una etiqueta de rango. Sin ELSE en
 * el original -> NULL si no hay elementos o el valor no es 1-4 (se agrega `ELSE NULL` explicito en
 * Postgres, equivalente).
 *
 * *** Bug real de produccion CORREGIDO a proposito (no replicado) - decision tomada con el cliente
 * despues de verificar con datos reales ***: `inventario` buscaba la clave `facility_own` DENTRO
 * del array `inventory` de `assets` - pero segun InventoryView, los elementos de `inventory` tienen
 * las claves `inventory_q`/`inventory_est`/`inventory_unit`/`inventory_name`, NUNCA `facility_own`
 * (ese es un campo de `facility`, no de `inventory` - copy-paste-error de la consulta de
 * `maquinaria`/`instalaciones`, ademas las etiquetas de rango ya codificadas en el CASE de abajo
 * - '< 100.000', '100.001 - 1.000.000', etc. - coinciden exactamente con la escala 1-4 de
 * `inventory_est` en InventoryView, no con `facility_own`, que ademas solo tiene 3 valores validos
 * de sentido distinto: Propia/Alquilada/Ambas). Verificado antes de corregir: el campo
 * `facility_own` (usado hoy en produccion) solo aparece, por un artefacto de captura historico, en
 * 20 elementos de 15 de 402 empresas; `inventory_est` (el campo correcto) tiene datos validos 1-4
 * en 72 de 402 empresas - la version corregida no solo es mas correcta semanticamente, da un dato
 * util en 4.8x mas empresas. Se cambia la clave buscada a `inventory_est`.
 *
 * El `GROUP_CONCAT(DISTINCT ... SEPARATOR ', ')` de Sector/Servicios (sin ORDER BY explicito en el
 * original) se traduce con `string_agg(DISTINCT ..., ', ' ORDER BY ...)` - Postgres exige el
 * ORDER BY con DISTINCT en un agregado; se ordena alfabeticamente y se verifica con db:diff-view
 * si esto genera alguna diferencia de orden (mismo caso ya aceptado en catalogoView/ClientsView).
 *
 * `employee`/`facility`/`machinery`/`inventory` son `$table->json(...)` de Laravel = tipo `json` en
 * Postgres - se castea `::jsonb` en cada subconsulta.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "capacityView"');
        DB::connection('pgsql')->statement('
            CREATE VIEW "capacityView" AS select
                empresas.id as id,
                empresas.name,
                string_agg(DISTINCT sectors.name, \', \' ORDER BY sectors.name) as "Sector",
                string_agg(DISTINCT services.name, \', \' ORDER BY services.name) as "Servicios",
                (
                    SELECT SUM(round(coalesce((elem ->> \'junior_q\')::numeric, 0)))
                         + SUM(round(coalesce((elem ->> \'medium_q\')::numeric, 0)))
                         + SUM(round(coalesce((elem ->> \'senior_q\')::numeric, 0)))
                    FROM assets a2
                    CROSS JOIN LATERAL jsonb_array_elements((a2.employee)::jsonb) AS elem
                    WHERE a2.empresa_id = empresas.id
                ) AS rrhh,
                (
                    SELECT SUM(round(coalesce((elem ->> \'facility_q\')::numeric, 0)))
                    FROM assets a2
                    CROSS JOIN LATERAL jsonb_array_elements((a2.facility)::jsonb) AS elem
                    WHERE a2.empresa_id = empresas.id
                ) AS instalaciones,
                (
                    SELECT CASE MAX(round((elem ->> \'machinery_qid\')::numeric))
                        WHEN 1 THEN \'1-10\'
                        WHEN 2 THEN \'11-50\'
                        WHEN 3 THEN \'51-100\'
                        WHEN 4 THEN \'> 100\'
                        ELSE NULL
                    END
                    FROM assets a2
                    CROSS JOIN LATERAL jsonb_array_elements((a2.machinery)::jsonb) AS elem
                    WHERE a2.empresa_id = empresas.id
                ) AS maquinaria,
                (
                    SELECT CASE MAX(round((elem ->> \'inventory_est\')::numeric))
                        WHEN 1 THEN \'< 100.000\'
                        WHEN 2 THEN \'100.001 - 1.000.000\'
                        WHEN 3 THEN \'1.000.001 - 10.000.000\'
                        WHEN 4 THEN \'> 10.000.000\'
                        ELSE NULL
                    END
                    FROM assets a2
                    CROSS JOIN LATERAL jsonb_array_elements((a2.inventory)::jsonb) AS elem
                    WHERE a2.empresa_id = empresas.id AND (elem ->> \'inventory_est\') IS NOT NULL
                ) AS inventario
            from empresas
            left join empresa_sector_service on empresas.id = empresa_sector_service.empresa_id
            left join services on empresa_sector_service.service_id = services.id
            left join sectors on services.sectors_id = sectors.id
            GROUP BY empresas.id, empresas.name
        ');
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "capacityView"');
    }
};

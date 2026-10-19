<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reescritura a PostgreSQL de InventoryView (Fase 2).
 *
 * *** DRIFT CONFIRMADO (mismo patron de copy-paste que MachineryView/FacilityView) ***: el archivo
 * de migracion (database/migrations_mysql_only_views/2023_05_31_233119_create_inventory_view.php)
 * tiene `ELSE '> 100'` / `ELSE '> 10.000.000 USD'` genericos en los CASE internos de
 * inventory_q/inventory_est. La definicion REAL (SHOW CREATE VIEW InventoryView) tiene una rama
 * explicita para el valor 4 seguida de `ELSE NULL`. Se escribe contra la definicion real.
 *
 * *** Bug preexistente corregido de paso (senalado en el plan) ***: el `down()` de esa misma
 * migracion borra `FacilityView` en vez de `InventoryView` (copy-paste sin actualizar). Esta
 * migracion nueva usa el nombre correcto.
 *
 * `JSON_TABLE(a.inventory, '$[*]' COLUMNS(...)) e`: a diferencia de Resource/Machinery/Facility,
 * aqui `inventory_name` se declara `TINYTEXT` (no INT) en el JSON_TABLE original, y se compara
 * igual `e.inventory_name = 0/1` - MySQL hace coercion numerica implicita de texto a numero en la
 * comparacion. Se declara `inventory_name` (junto con `inventory_q`/`inventory_est`, por la misma
 * razon defensiva que en FacilityView: ninguno tiene decimales en los datos reales hoy, verificado,
 * pero se usa `numeric` en vez de `int` por si acaso) como `numeric` en `jsonb_to_recordset` y se
 * compara con `round(...)::int`. `inventory_unit` se declara `text` (dato real: texto libre, p.ej.
 * unidades de medida) y se usa directo, sin cast.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "InventoryView"');
        DB::connection('pgsql')->statement("
            CREATE VIEW \"InventoryView\" AS select
                a.empresa_id as id,
                max(empresas.name) as name,
                MAX(CASE WHEN round(e.inventory_name)::int = 0 THEN (CASE WHEN round(e.inventory_q)::int = 1 THEN '1 - 10' WHEN round(e.inventory_q)::int = 2 THEN '11 - 50' WHEN round(e.inventory_q)::int = 3 THEN '51 - 100' WHEN round(e.inventory_q)::int = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Materia_q\",
                MAX(CASE WHEN round(e.inventory_name)::int = 0 THEN (CASE WHEN round(e.inventory_est)::int = 1 THEN '< 100.000 USD' WHEN round(e.inventory_est)::int = 2 THEN '100.001 - 1.000.000 USD' WHEN round(e.inventory_est)::int = 3 THEN '1.000.001 - 10.000.000 USD' WHEN round(e.inventory_est)::int = 4 THEN '> 10.000.000 USD' ELSE NULL END) ELSE ' ' END) AS \"Materia_est\",
                MAX(CASE WHEN round(e.inventory_name)::int = 0 THEN e.inventory_unit ELSE ' ' END) AS \"Materia_unit\",
                MAX(CASE WHEN round(e.inventory_name)::int = 1 THEN (CASE WHEN round(e.inventory_q)::int = 1 THEN '1 - 10' WHEN round(e.inventory_q)::int = 2 THEN '11 - 50' WHEN round(e.inventory_q)::int = 3 THEN '51 - 100' WHEN round(e.inventory_q)::int = 4 THEN '> 100' ELSE NULL END) ELSE ' ' END) AS \"Producto_q\",
                MAX(CASE WHEN round(e.inventory_name)::int = 1 THEN (CASE WHEN round(e.inventory_est)::int = 1 THEN '< 100.000 USD' WHEN round(e.inventory_est)::int = 2 THEN '100.001 - 1.000.000 USD' WHEN round(e.inventory_est)::int = 3 THEN '1.000.001 - 10.000.000 USD' WHEN round(e.inventory_est)::int = 4 THEN '> 10.000.000 USD' ELSE NULL END) ELSE ' ' END) AS \"Producto_est\",
                MAX(CASE WHEN round(e.inventory_name)::int = 1 THEN e.inventory_unit ELSE ' ' END) AS \"Producto_unit\"
            from assets a
            join lateral jsonb_to_recordset((a.inventory)::jsonb) as e(
                inventory_q numeric, inventory_est numeric, inventory_unit text, inventory_name numeric
            ) on true
            join empresas on a.empresa_id = empresas.id
            GROUP BY a.empresa_id
        ");
    }

    public function down()
    {
        DB::connection('pgsql')->statement('DROP VIEW IF EXISTS "InventoryView"');
    }
};
